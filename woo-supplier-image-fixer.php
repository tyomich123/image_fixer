<?php
/**
 * Plugin Name: Woo Image Fixer (Safe Sideload, Watchdog, Loopback)
 * Description: Фонове відновлення зламаних зображень товарів з XML-фіду, з обмеженням часу на картинку, безпечним завантаженням, вотчдогом без спаму та loopback runner'ом.
 * Version: 2.4.0 (Optimized)
 * Author: ChatGPT + Improvements
 */

if (!defined('ABSPATH')) exit;

class Woo_Image_Fixer {
    /* Cron hooks */
    const CRON_HOOK_BATCH    = 'woo_image_fixer_cron_process';
    const CRON_HOOK_WATCHDOG = 'woo_image_fixer_cron_watchdog';

    /* Options & constants */
    const SOURCE_URL   = 'https://smtm.com.ua/_prices/import-retail-ua-2.xml';
    const PLACEHOLDER_URL = 'https://lyuboshchi.com.ua/wp-content/uploads/woocommerce-placeholder-600x600.png';
    const OPTION_KEY   = 'woo_image_fixer_state';
    const LOG_KEY      = 'woo_image_fixer_log';

    // ОПТИМІЗОВАНІ КОНСТАНТИ ДЛЯ МІНІМАЛЬНОГО НАВАНТАЖЕННЯ
    const BATCH_SIZE         = 3;        // Зменшено з 50 до 3
    const LOG_LIMIT          = 500;      // Зменшено з 2000 до 500
    const SCAN_BATCH_SIZE    = 20;       // Зменшено з 200 до 20

    const LOCK_KEY           = 'woo_image_fixer_lock';
    const LOCK_TTL           = 180;
    const RUN_HARD_LIMIT     = 45;       // Зменшено з 540 до 45 секунд
    const IMAGE_TIMEOUT      = 30;       // Зменшено з 60 до 30
    const IMAGE_TRIES        = 2;        // Зменшено з 3 до 2
    const NEXT_DELAY         = 60;       // Збільшено з 10 до 60 секунд
    const STALL_SEC          = 300;      // Збільшено з 240 до 300

    const WD_MIN_LOG_GAP     = 600;      // Збільшено з 300 до 600
    const WD_LAST_LOG_OPT    = 'woo_image_fixer_last_wd_log';

    const RUNNER_TOKEN_OPTION = 'woo_image_fixer_runner_token';

    public function __construct(){
        add_action(self::CRON_HOOK_BATCH,    [$this,'process_batch_cron']);
        add_action(self::CRON_HOOK_WATCHDOG, [$this,'watchdog_tick']);

        register_activation_hook(__FILE__, [$this,'on_activate']);
        register_deactivation_hook(__FILE__, [$this,'on_deactivate']);

        add_action('admin_menu', [$this,'add_admin_page']);

        add_action('wp_ajax_wif_start',         [$this,'ajax_start']);
        add_action('wp_ajax_wif_scan',          [$this,'ajax_scan']);
        add_action('wp_ajax_wif_status',        [$this,'ajax_status']);
        add_action('wp_ajax_wif_nudge',         [$this,'ajax_nudge']);
        add_action('wp_ajax_wif_restart',       [$this,'ajax_restart']);
        add_action('wp_ajax_wif_runner',        [$this,'ajax_runner']);
        add_action('wp_ajax_wif_check_product', [$this,'ajax_check_product']);

        add_action('admin_init', function(){
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
        });

        // Змінено з 'minute' на 'five_minutes'
        add_filter('cron_schedules', function($s){
            if (!isset($s['five_minutes'])) {
                $s['five_minutes'] = ['interval'=>300, 'display'=>'Every 5 Minutes'];
            }
            return $s;
        });
    }

    /* ---------------- Activation / Deactivation ---------------- */

    public function on_activate(){
        $this->ensure_watchdog();
        $this->ensure_runner_token();
        $this->log('ImageFixer активовано. Watchdog — кожні 5 хвилин.');
    }

    public function on_deactivate(){
        foreach ([self::CRON_HOOK_BATCH, self::CRON_HOOK_WATCHDOG] as $hook) {
            while ($ts = wp_next_scheduled($hook)) wp_unschedule_event($ts, $hook);
        }
        delete_transient(self::LOCK_KEY);
        $this->log('ImageFixer деактивовано. Крон-події знято.');
    }

    private function ensure_watchdog(){
        if (!wp_next_scheduled(self::CRON_HOOK_WATCHDOG)) {
            // Змінено з 'minute' на 'five_minutes'
            wp_schedule_event(time()+300, 'five_minutes', self::CRON_HOOK_WATCHDOG);
        }
    }

    private function ensure_runner_token(){
        $tok = get_option(self::RUNNER_TOKEN_OPTION);
        if (!$tok) {
            $tok = wp_generate_password(32, false, false);
            update_option(self::RUNNER_TOKEN_OPTION, $tok, false);
        }
        return $tok;
    }

    /* ---------------- Admin UI ---------------- */

    public function add_admin_page(){
        add_submenu_page(
            'woocommerce',
            'Woo Image Fixer',
            'Woo Image Fixer',
            'manage_woocommerce',
            'woo-image-fixer',
            [$this,'render_admin_page']
        );
    }

    public function render_admin_page(){
        $next_batch = wp_next_scheduled(self::CRON_HOOK_BATCH);
        $tok        = esc_html(get_option(self::RUNNER_TOKEN_OPTION, '—'));
        $next_batch_label = $next_batch ? esc_html(date_i18n('Y-m-d H:i:s',$next_batch)) : '—';
        echo '<div class="wrap wif-app">';
        echo '<style>
            .wif-app { max-width: 1200px; }
            .wif-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:16px 0 24px; }
            .wif-title h1 { margin:0 0 6px; font-size:24px; }
            .wif-subtitle { color:#6b7280; font-size:13px; }
            .wif-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#eef2ff; color:#4338ca; font-size:12px; font-weight:600; }
            .wif-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:16px; }
            .wif-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
            .wif-card h2 { margin:0 0 12px; font-size:16px; }
            .wif-actions { display:flex; flex-wrap:wrap; gap:8px; }
            .wif-progress { height:12px; background:#f3f4f6; border-radius:999px; overflow:hidden; position:relative; }
            .wif-progress span { position:absolute; left:0; top:0; height:100%; width:0; background:linear-gradient(90deg,#2563eb,#4f46e5); transition:width .2s ease; }
            .wif-stat-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
            .wif-stat { border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; background:#f9fafb; }
            .wif-stat label { display:block; font-size:11px; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.04em; }
            .wif-stat strong { font-size:18px; color:#111827; }
            .wif-meta { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; font-size:12px; color:#4b5563; }
            .wif-meta code { font-size:11px; word-break:break-all; overflow-wrap:anywhere; }
            .wif-log { max-height:360px; overflow:auto; background:#0f172a; color:#e2e8f0; border-radius:10px; padding:12px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; line-height:1.5; }
            .wif-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#ecfeff; color:#0e7490; }
            .wif-status { display:flex; flex-wrap:wrap; gap:8px; }
            .wif-status .wif-pill.is-on { background:#dcfce7; color:#166534; }
            .wif-status .wif-pill.is-off { background:#fee2e2; color:#991b1b; }
            .wif-footer { margin-top:18px; color:#94a3b8; font-size:11px; }
            .wif-sku-list { max-height:220px; overflow:auto; border:1px solid #e5e7eb; border-radius:10px; padding:10px; background:#f8fafc; font-size:12px; color:#334155; }
            .wif-sku-list ul { margin:0; padding-left:18px; }
            .wif-sku-list li { word-break:break-all; overflow-wrap:anywhere; margin:0 0 4px; }
            @media (max-width: 960px) {
                .wif-stat-grid { grid-template-columns:repeat(2,1fr); }
                .wif-meta { grid-template-columns:1fr; }
            }
        </style>';

        echo '<div class="wif-header">';
        echo '<div class="wif-title">';
        echo '<h1>Woo Image Fixer</h1>';
        echo '<div class="wif-subtitle">Інтелектуальне виправлення зображень у фоновому режимі з контролем ресурсів.</div>';
        echo '</div>';
        echo '<span class="wif-badge">Оптимізований фоновий режим</span>';
        echo '</div>';

        echo '<div class="wif-grid">';
        echo '<div class="wif-card" style="grid-column: span 8;">';
        echo '<h2>Керування процесом</h2>';
        echo '<div class="wif-actions">';
        echo '<button id="wif-start" class="button button-primary">Scan & Fix (start/resume)</button>';
        echo '<button id="wif-nudge" class="button">Process One Batch Now</button>';
        echo '<button id="wif-restart" class="button button-secondary">Restart from Zero</button>';
        echo '<button id="wif-refresh" class="button">Refresh Status</button>';
        echo '</div>';
        echo '<div style="margin-top:16px;">';
        echo '<div class="wif-progress"><span id="wif-bar-in"></span></div>';
        echo '<div id="wif-progress-text" style="margin-top:8px;font-size:12px;color:#4b5563;">0%</div>';
        echo '</div>';
        echo '<div class="wif-stat-grid" style="margin-top:16px;">';
        echo '<div class="wif-stat"><label>Всього</label><strong id="wif-stat-total">0</strong></div>';
        echo '<div class="wif-stat"><label>Опрацьовано</label><strong id="wif-stat-current">0</strong></div>';
        echo '<div class="wif-stat"><label>Виправлено</label><strong id="wif-stat-fixed">0</strong></div>';
        echo '<div class="wif-stat"><label>Пропущено</label><strong id="wif-stat-skipped">0</strong></div>';
        echo '<div class="wif-stat"><label>Помилки</label><strong id="wif-stat-errors">0</strong></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wif-card" style="grid-column: span 4;">';
        echo '<h2>Стан і параметри</h2>';
        echo '<div class="wif-status">';
        echo '<span class="wif-pill" id="wif-status-processing">Processing: —</span>';
        echo '<span class="wif-pill" id="wif-status-scanning">Scanning: —</span>';
        echo '<span class="wif-pill" id="wif-status-finished">Finished: —</span>';
        echo '</div>';
        echo '<div class="wif-meta" style="margin-top:14px;">';
        echo '<div><strong>Фід постачальника</strong><br><code>'.esc_html(self::SOURCE_URL).'</code></div>';
        echo '<div><strong>Next batch</strong><br><span id="wif-next">'.$next_batch_label.'</span></div>';
        echo '<div><strong>Batch size</strong><br>'.esc_html(self::BATCH_SIZE).' товарів</div>';
        echo '<div><strong>Scan batch</strong><br>'.esc_html(self::SCAN_BATCH_SIZE).' товарів</div>';
        echo '<div><strong>Timeout</strong><br>'.esc_html(self::IMAGE_TIMEOUT).' сек</div>';
        echo '<div><strong>Runner token</strong><br><code>'.$tok.'</code></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wif-card" style="grid-column: span 12;">';
        echo '<h2>Журнал виконання</h2>';
        echo '<div id="wif-log" class="wif-log"></div>';
        echo '<div class="wif-footer">Плагін працює у фоновому режимі й продовжує роботу навіть якщо вкладку закрито.</div>';
        echo '</div>';

        echo '<div class="wif-card" style="grid-column: span 4;">';
        echo '<h2>SKU: виправлені</h2>';
        echo '<div id="wif-sku-fixed" class="wif-sku-list"></div>';
        echo '</div>';
        echo '<div class="wif-card" style="grid-column: span 4;">';
        echo '<h2>SKU: помилки</h2>';
        echo '<div id="wif-sku-errors" class="wif-sku-list"></div>';
        echo '</div>';
        echo '<div class="wif-card" style="grid-column: span 4;">';
        echo '<h2>SKU: немає зображень у фіді</h2>';
        echo '<div id="wif-sku-no-urls" class="wif-sku-list"></div>';
        echo '</div>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            let poll=null;
            let scanPoll=null;
            
            function pct(d){ if(!d||!d.total) return 0; const p=Math.round((d.current/d.total)*100); return isFinite(p)?p:0; }
            function draw(d){
                const p=pct(d);
                $('#wif-bar-in').css('width',p+'%');
                $('#wif-progress-text').text(p+'% · '+(d.current||0)+' / '+(d.total||0));
                $('#wif-stat-total').text(d.total||0);
                $('#wif-stat-current').text(d.current||0);
                $('#wif-stat-fixed').text((d.stats && d.stats.img_fixed) ? d.stats.img_fixed : 0);
                $('#wif-stat-skipped').text((d.stats && d.stats.skipped) ? d.stats.skipped : 0);
                $('#wif-stat-errors').text((d.stats && d.stats.errors) ? d.stats.errors : 0);

                const proc = !!(d && d.processing);
                const scan = !!(d && d.scanning);
                const fin = !!(d && d.finished);

                $('#wif-status-processing').text('Processing: '+(proc?'Yes':'No'))
                    .toggleClass('is-on', proc).toggleClass('is-off', !proc);
                $('#wif-status-scanning').text('Scanning: '+(scan?'Yes':'No'))
                    .toggleClass('is-on', scan).toggleClass('is-off', !scan);
                $('#wif-status-finished').text('Finished: '+(fin?'Yes':'No'))
                    .toggleClass('is-on', fin).toggleClass('is-off', !fin);
            }
            function drawLog(lines){
                $('#wif-log').text((lines||[]).join('\n'));
                const el=document.getElementById('wif-log'); 
                if(el) el.scrollTop=el.scrollHeight;
            }
            function drawSkuList(selector, list){
                const items = Array.isArray(list) ? list : [];
                if (!items.length) {
                    $(selector).html('<em>Поки що пусто</em>');
                    return;
                }
                const html = '<ul>' + items.map(function(sku){
                    return '<li>'+String(sku)+'</li>';
                }).join('') + '</ul>';
                $(selector).html(html);
            }
            function status(cb){
                $.post(ajaxurl,{action:'wif_status'},function(r){
                    if(r&&r.success){
                        draw(r.data.state||{});
                        drawLog(r.data.log||[]);
                        drawSkuList('#wif-sku-fixed', r.data.sku_fixed);
                        drawSkuList('#wif-sku-errors', r.data.sku_errors);
                        drawSkuList('#wif-sku-no-urls', r.data.sku_no_urls);
                        if(r.data.next_batch) $('#wif-next').text(r.data.next_batch);
                        if(typeof cb==='function') cb(r.data);
                    }
                });
            }
            function startPoll(){
                if(poll) clearInterval(poll);
                // Збільшено інтервал опитування з 3 до 10 секунд
                poll=setInterval(status, 10000);
            }
            function continueScan(){
                $.post(ajaxurl,{action:'wif_scan'},function(r){
                    status();
                    if(r && r.success && r.data && !r.data.scan_complete){
                        // Збільшено затримку з 500мс до 2 секунд
                        setTimeout(continueScan, 2000);
                    } else {
                        if(scanPoll) clearInterval(scanPoll);
                        startPoll();
                    }
                });
            }
            $('#wif-start').on('click',function(){
                $.post(ajaxurl,{action:'wif_start'},function(r){
                    if(r && r.success){
                        // Збільшено інтервал опитування з 2 до 5 секунд
                        scanPoll = setInterval(status, 5000);
                        continueScan();
                    }
                });
            });
            $('#wif-nudge').on('click',function(){
                $.post(ajaxurl,{action:'wif_nudge'},function(r){ status(); });
            });
            $('#wif-restart').on('click',function(){
                if(!confirm('Рестарт сканування з нуля?')) return;
                $.post(ajaxurl,{action:'wif_restart'},function(r){ 
                    if(r && r.success){
                        scanPoll = setInterval(status, 5000);
                        continueScan();
                    }
                });
            });
            $('#wif-refresh').on('click',function(){ status(); });
            status(function(d){ 
                if(d && d.state){
                    if(d.state.scanning){
                        scanPoll = setInterval(status, 5000);
                        continueScan();
                    } else if(d.state.processing){
                        startPoll();
                    }
                }
            });
        });
        </script>
        </div>
        <?php
    }

    /* ---------------- Watchdog ---------------- */

    public function watchdog_tick(){
        $state = get_option(self::OPTION_KEY);
        if (!is_array($state) || empty($state['processing']) || !empty($state['finished'])) return;

        $next = wp_next_scheduled(self::CRON_HOOK_BATCH);
        $need_schedule = !$next;

        $last = isset($state['last_activity']) ? strtotime($state['last_activity']) : 0;
        $stalled = ($last>0 && (time()-$last) > self::STALL_SEC);

        if ($need_schedule || $stalled){
            // Збільшено затримку з 5 до 30 секунд
            wp_schedule_single_event(time()+30, self::CRON_HOOK_BATCH);
            // ВИДАЛЕНО spawn_runner_async() для зменшення навантаження

            $lastLog = (int)get_option(self::WD_LAST_LOG_OPT, 0);
            if ( (time() - $lastLog) >= self::WD_MIN_LOG_GAP ){
                if ($stalled) $this->log('Watchdog: stalled; rescheduled batch.');
                else          $this->log('Watchdog: no batch scheduled — scheduling.');
                update_option(self::WD_LAST_LOG_OPT, time(), false);
            }
        }
    }

    /* ---------------- Admin AJAX ---------------- */

    public function ajax_start(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();

        $offers = $this->fetch_xml_offers();
        if (empty($offers)) wp_send_json_error(['msg'=>'Feed empty']);

        global $wpdb;
        $all_products = $wpdb->get_col("
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type='product' AND p.post_status='publish'
        ");

        $state = [
            'mode'           => 'scanning',
            'scanning'       => true,
            'offer_map'      => $this->build_offer_image_map($offers),
            'scan_products'  => array_values(array_map('intval', $all_products)),
            'scan_total'     => count($all_products),
            'scan_current'   => 0,
            'broken_ids'     => [],
            'ids'            => [],
            'total'          => 0,
            'current'        => 0,
            'stats'          => ['skipped'=>0,'errors'=>0,'img_fixed'=>0],
            'sku_fixed'      => [],
            'sku_errors'     => [],
            'sku_no_urls'    => [],
            'processing'     => false,
            'started_at'     => current_time('mysql'),
            'finished'       => false,
            'last_activity'  => current_time('mysql'),
            'blacklist'      => [],
        ];
        update_option(self::OPTION_KEY, $state, false);
        $this->log('Starting batch scan of '.count($all_products).' products...');
        wp_send_json_success(['ok'=>true]);
    }

    public function ajax_scan(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();
        
        $state = get_option(self::OPTION_KEY);
        if (!is_array($state) || empty($state['scanning'])) {
            wp_send_json_success(['scan_complete'=>true]);
            return;
        }

        $scan_products = $state['scan_products'];
        $scan_total    = (int)$state['scan_total'];
        $scan_current  = (int)$state['scan_current'];
        $broken_ids    = is_array($state['broken_ids']) ? $state['broken_ids'] : [];

        $batch_end = min($scan_current + self::SCAN_BATCH_SIZE, $scan_total);
        
        for ($i = $scan_current; $i < $batch_end; $i++) {
            $pid = (int)$scan_products[$i];
            if ($this->is_product_images_broken($pid)) {
                $broken_ids[] = $pid;
            }
        }

        $scan_current = $batch_end;
        $scan_complete = ($scan_current >= $scan_total);

        if ($scan_complete) {
            $this->log('Scan complete: found '.count($broken_ids).' broken products out of '.$scan_total);
            
            $state['mode']         = 'fix_images';
            $state['scanning']     = false;
            $state['ids']          = $broken_ids;
            $state['total']        = count($broken_ids);
            $state['current']      = 0;
            $state['processing']   = true;
            $state['last_activity'] = current_time('mysql');
            
            update_option(self::OPTION_KEY, $state, false);
            
            if (count($broken_ids) > 0) {
                // Збільшено затримку з 5 до 60 секунд
                $this->schedule_next_batch_soon(60);
                // ВИДАЛЕНО spawn_runner_async()
            } else {
                $state['processing'] = false;
                $state['finished'] = true;
                update_option(self::OPTION_KEY, $state, false);
            }
            
            wp_send_json_success(['scan_complete'=>true, 'broken_count'=>count($broken_ids)]);
        } else {
            $this->log("Scan progress: {$scan_current}/{$scan_total} (found ".count($broken_ids)." broken so far)");
            
            $state['scan_current']  = $scan_current;
            $state['broken_ids']    = $broken_ids;
            $state['last_activity'] = current_time('mysql');
            update_option(self::OPTION_KEY, $state, false);
            
            wp_send_json_success([
                'scan_complete' => false,
                'scan_current'  => $scan_current,
                'scan_total'    => $scan_total,
                'broken_count'  => count($broken_ids)
            ]);
        }
    }

    public function ajax_restart(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();
        delete_option(self::OPTION_KEY);
        $this->ajax_start();
    }

    public function ajax_status(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();
        $state = get_option(self::OPTION_KEY);
        $log   = get_option(self::LOG_KEY);
        $next  = wp_next_scheduled(self::CRON_HOOK_BATCH);
        $resp = [
            'state' => is_array($state) ? [
                'total'      => (int)($state['total']??0),
                'current'    => (int)($state['current']??0),
                'stats'      => $state['stats']??[],
                'processing' => !empty($state['processing']),
                'finished'   => !empty($state['finished']),
                'scanning'   => !empty($state['scanning']),
            ] : ['total'=>0,'current'=>0,'stats'=>[],'processing'=>false,'finished'=>false,'scanning'=>false],
            'log'  => is_array($log)?array_slice($log, -100):[],
            'sku_fixed'  => is_array($state['sku_fixed']??null) ? array_values($state['sku_fixed']) : [],
            'sku_errors' => is_array($state['sku_errors']??null) ? array_values($state['sku_errors']) : [],
            'sku_no_urls' => is_array($state['sku_no_urls']??null) ? array_values($state['sku_no_urls']) : [],
            'next_batch' => $next ? date_i18n('Y-m-d H:i:s', $next) : '—',
        ];
        wp_send_json_success($resp);
    }

    public function ajax_nudge(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();
        $this->process_batch_cron();
        wp_send_json_success(['ok'=>true]);
    }

    public function ajax_runner(){
        $tok = get_option(self::RUNNER_TOKEN_OPTION);
        $ok = (isset($_POST['token']) && hash_equals((string)$tok, (string)$_POST['token'])) || current_user_can('manage_woocommerce');
        if(!$ok) wp_send_json_error(['msg'=>'forbidden']);
        $this->process_batch_cron();
        wp_send_json_success(['ok'=>true]);
    }

    public function ajax_check_product(){
        if(!current_user_can('manage_woocommerce')) wp_send_json_error();
        
        $pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
        if (!$pid) wp_send_json_error(['msg'=>'No PID']);
        
        $sku = get_post_meta($pid, '_sku', true);
        $thumb = (int)get_post_meta($pid, '_thumbnail_id', true);
        $gallery = get_post_meta($pid, '_product_image_gallery', true);
        
        $thumb_file = $thumb > 0 ? get_attached_file($thumb) : null;
        $thumb_url = $thumb > 0 ? wp_get_attachment_url($thumb) : null;
        
        $info = [
            'pid' => $pid,
            'sku' => $sku,
            'normalized_sku' => $this->normalize_sku($sku),
            'thumb_id' => $thumb,
            'thumb_file' => $thumb_file,
            'thumb_exists' => $thumb_file ? file_exists($thumb_file) : false,
            'thumb_url' => $thumb_url,
            'gallery' => $gallery,
            'is_broken' => $this->is_product_images_broken($pid),
        ];
        
        wp_send_json_success($info);
    }

    /* ---------------- Core: batch ---------------- */

    public function process_batch_cron(){
        if (get_transient(self::LOCK_KEY)) return;
        set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);

        $startTs = time();
        ignore_user_abort(true);
        @set_time_limit(self::RUN_HARD_LIMIT + 30);
        
        // ОБМЕЖЕННЯ ПАМ'ЯТІ для зменшення навантаження
        if (function_exists('wp_raise_memory_limit')) @wp_raise_memory_limit('admin');

        try{
            $state = get_option(self::OPTION_KEY);
            if (!is_array($state) || !empty($state['finished']) || empty($state['processing'])) return;

            $total   = (int)$state['total'];
            $current = (int)$state['current'];
            $stats   = $state['stats'];
            $ids     = $state['ids'];
            $offer   = is_array($state['offer_map']) ? $state['offer_map'] : [];
            $black   = is_array($state['blacklist']) ? $state['blacklist'] : [];
            $skuFixed = is_array($state['sku_fixed']) ? $state['sku_fixed'] : [];
            $skuErrors = is_array($state['sku_errors']) ? $state['sku_errors'] : [];
            $skuNoUrls = is_array($state['sku_no_urls']) ? $state['sku_no_urls'] : [];

            $processedThisRun = 0;

            while ($current < $total) {
                if ((time() - $startTs) >= self::RUN_HARD_LIMIT) {
                    $this->log('Soft stop by time limit; progress saved at '.$current.'/'.$total);
                    break;
                }

                // Більш агресивна перевірка пам'яті
                if (memory_get_usage() > (0.7 * $this->get_memory_limit())) {
                    $this->log('Memory limit approaching (70%), soft stop at '.$current.'/'.$total);
                    break;
                }

                $pid = (int)$ids[$current];
                $sku = $this->normalize_sku(get_post_meta($pid, '_sku', true));
                
                if ($sku===''){
                    $this->log("PID={$pid}: no SKU, skipped");
                    $stats['skipped']++; 
                    $current++; 
                    continue;
                }

                if (isset($black[$sku])) { 
                    $stats['skipped']++; 
                    $current++; 
                    continue; 
                }

                try{
                    $this->log("Processing PID={$pid} SKU={$sku}");
                    
                    $urls = isset($offer[$sku]) ? $offer[$sku] : [];
                    if (!empty($urls)) {
                        $fixed = $this->safe_fix_images($pid, $sku, $urls);
                        if ($fixed>0){
                            $stats['img_fixed'] += $fixed;
                            $this->log("✓ Fixed {$fixed} images PID={$pid} SKU={$sku}");
                            $skuFixed[] = $sku;
                        } else {
                            $this->log("✗ No images sideloaded PID={$pid} SKU={$sku} (blacklisted)");
                            $black[$sku]=1;
                            $stats['skipped']++;
                        }
                    } else {
                        $this->log("✗ No URLs in feed for SKU={$sku}");
                        $stats['skipped']++;
                        $skuNoUrls[] = $sku;
                    }
                } catch(\Throwable $e){
                    $stats['errors']++;
                    $skuErrors[] = $sku;
                    $this->log('Exception PID='.$pid.' SKU='.$sku.': '.$e->getMessage());
                }

                $current++;
                $processedThisRun++;

                // Зберігаємо стан після кожного товару для безпеки
                $state['current']       = $current;
                $state['stats']         = $stats;
                $state['blacklist']     = $black;
                $state['sku_fixed']     = $skuFixed;
                $state['sku_errors']    = $skuErrors;
                $state['sku_no_urls']   = $skuNoUrls;
                $state['last_activity'] = current_time('mysql');
                update_option(self::OPTION_KEY, $state, false);
                
                // ДОДАНО: Пауза між товарами для зменшення навантаження
                if ($processedThisRun < self::BATCH_SIZE) {
                    sleep(2); // 2 секунди між товарами
                }
            }

            $finished = ($current >= $total);
            $state['current']       = $current;
            $state['stats']         = $stats;
            $state['blacklist']     = $black;
            $state['sku_fixed']     = $skuFixed;
            $state['sku_errors']    = $skuErrors;
            $state['sku_no_urls']   = $skuNoUrls;
            $state['last_activity'] = current_time('mysql');
            $state['processing']    = !$finished;
            $state['finished']      = $finished;
            update_option(self::OPTION_KEY, $state, false);

            if ($finished) {
                $this->log('✓✓✓ ImageFixer FINISHED '.$current.'/'.$total.' | Fixed:'.$stats['img_fixed'].' Errors:'.$stats['errors']);
                if ($ts = wp_next_scheduled(self::CRON_HOOK_BATCH)) wp_unschedule_event($ts, self::CRON_HOOK_BATCH);
            } else {
                $this->log('ImageFixer progress '.$current.'/'.$total.'. Scheduling next.');
                // Збільшено затримку між батчами
                $this->schedule_next_batch_soon(self::NEXT_DELAY + 30);
                // ВИДАЛЕНО spawn_runner_async()
            }
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function schedule_next_batch_soon($delay=10){
        $when = time() + max(30, (int)$delay); // Мінімум 30 секунд
        $next = wp_next_scheduled(self::CRON_HOOK_BATCH);
        if (!$next || $next > $when) {
            if ($next) wp_unschedule_event($next, self::CRON_HOOK_BATCH);
            wp_schedule_single_event($when, self::CRON_HOOK_BATCH);
        }
    }

    /* ---------------- Safe image handling ---------------- */

    private function safe_fix_images($pid, $sku, array $urls){
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $this->log("  Starting fix for PID={$pid} SKU={$sku}, URLs count: ".count($urls));

        $ids = [];
        foreach ($urls as $idx => $u){
            $u = trim($u); 
            if ($u==='') {
                $this->log("  URL #{$idx} is empty, skipped");
                continue;
            }

            $this->log("  Processing URL #{$idx}: {$u}");

            $existing = $this->get_attachment_by_url($u);
            if ($existing > 0) {
                if (!$this->is_attachment_broken($existing)) {
                    $ids[] = $existing;
                    $this->log("  → URL #{$idx} exists (ID={$existing}), reused");
                    continue;
                } else {
                    $this->log("  → URL #{$idx} exists (ID={$existing}) but broken, will re-download");
                }
            }

            $cands = array_unique(array_filter([
                $u,
                preg_replace('~^https://~','http://',$u),
                preg_replace('~^http://~','https://',$u),
            ]));

            $att_id = 0;
            foreach ($cands as $cidx=>$cand){
                $this->log("  Trying variant #{$cidx}: {$cand}");
                $att_id = $this->safe_sideload_single($cand, $pid, self::IMAGE_TIMEOUT, self::IMAGE_TRIES);
                if ($att_id>0) {
                    $this->log("  → URL #{$idx} sideloaded (ID={$att_id})");
                    break;
                }
            }
            if ($att_id>0) {
                $ids[]=$att_id;
            } else {
                $this->log("  ✗ URL #{$idx} failed to download: {$u}");
            }
            
            // ДОДАНО: Пауза між завантаженням зображень
            sleep(1);
        }

        if (empty($ids)) {
            $this->log("  ✗ No images downloaded for PID={$pid}");
            return 0;
        }

        $this->log("  Setting images for PID={$pid}: main={$ids[0]}, gallery=".count(array_slice($ids,1)));

        if (function_exists('wc_get_product')) {
            $p = wc_get_product($pid);
            if ($p){
                $old_thumb = $p->get_image_id();
                $old_gallery = $p->get_gallery_image_ids();
                
                $p->set_image_id($ids[0]);
                if (count($ids)>1) {
                    $p->set_gallery_image_ids(array_slice($ids,1));
                } else {
                    $p->set_gallery_image_ids([]);
                }
                $p->save();
                
                $new_thumb = $p->get_image_id();
                $new_gallery = $p->get_gallery_image_ids();
                
                $this->log("  Product saved: thumb {$old_thumb}→{$new_thumb}, gallery ".count($old_gallery)."→".count($new_gallery));
            } else {
                $this->log("  ✗ wc_get_product() returned false for PID={$pid}");
            }
        } else {
            set_post_thumbnail($pid, $ids[0]);
            if (count($ids)>1) {
                update_post_meta($pid, '_product_image_gallery', implode(',', array_slice($ids,1)));
            } else {
                delete_post_meta($pid, '_product_image_gallery');
            }
            $this->log("  Set via WordPress functions (non-WC mode)");
        }
        
        foreach($ids as $aid){
            $converted = $this->convert_attachment_to_webp($aid);
            $file = $converted ? $converted : get_attached_file($aid);
            if ($file && file_exists($file)) {
                wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $file));
            }
        }
        
        return count($ids);
    }

    private function safe_sideload_single($url, $post_id, $timeout, $tries){
        $tmp = $this->safe_download_to_tmp($url, $timeout, $tries);
        if (!$tmp) {
            $this->log("    Failed to download to tmp: {$url}");
            return 0;
        }

        $this->log("    Downloaded to tmp: {$tmp}, size: ".filesize($tmp)." bytes");

        $file_array = [
            'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
            'tmp_name' => $tmp,
        ];

        if (strpos($file_array['name'], '.')===false) $file_array['name'].='.jpg';

        $this->log("    Calling media_handle_sideload with name: {$file_array['name']}");

        $id = media_handle_sideload($file_array, $post_id, null);
        if (is_wp_error($id)) {
            @unlink($tmp);
            $this->log("    media_handle_sideload error: ".$id->get_error_message());
            return 0;
        }
        
        $this->log("    Successfully created attachment ID={$id}");
        return (int)$id;
    }

    private function safe_download_to_tmp($url, $timeout, $tries){
        $tmp = wp_tempnam($url);
        if (!$tmp) return false;

        $attempt = 0;

        while ($attempt < max(1,(int)$tries)) {
            $attempt++;
            $resp = wp_remote_get($url, [
                'timeout'   => (int)$timeout,
                'stream'    => true,
                'filename'  => $tmp,
                'headers'   => ['Accept'=>'image/*,*/*;q=0.8','Connection'=>'close'],
                'redirection' => 5,
                'sslverify' => false,
            ]);

            if (!is_wp_error($resp)) {
                $code = (int)wp_remote_retrieve_response_code($resp);
                if ($code>=200 && $code<300) {
                    if (file_exists($tmp) && filesize($tmp)>0) return $tmp;
                }
            }

            // Збільшено паузу між спробами
            sleep(min(5, $attempt * 2));
        }

        @unlink($tmp);
        return false;
    }

    private function get_attachment_by_url($url){
        global $wpdb;
        $file = basename(parse_url($url, PHP_URL_PATH));
        if (!$file) return 0;
        
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key='_wp_attached_file' 
            AND meta_value LIKE %s
            LIMIT 1",
            '%' . $wpdb->esc_like($file)
        );
        return (int)$wpdb->get_var($query);
    }

    /* ---------------- Helpers: XML / products ---------------- */

    private function fetch_xml_offers(){
        $this->log('Fetching XML: '.self::SOURCE_URL);
        $resp = wp_remote_get(self::SOURCE_URL, ['timeout'=>120,'redirection'=>5,'sslverify'=>false]);
        if (is_wp_error($resp)) { $this->log('HTTP error: '.$resp->get_error_message()); return []; }
        $xml = wp_remote_retrieve_body($resp);
        if (!$xml) { $this->log('Empty XML body'); return []; }

        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if(!$sx){ $this->log('XML parse error'); return []; }

        if     (isset($sx->shop->offers->offer))   $nodes = $sx->shop->offers->offer;
        elseif (isset($sx->offers->offer))         $nodes = $sx->offers->offer;
        elseif (isset($sx->products->product))     $nodes = $sx->products->product;
        elseif (isset($sx->offer))                 $nodes = $sx->offer;
        else                                       $nodes = [];

        $offers = [];
        foreach($nodes as $off){
            $attrId = isset($off['id']) ? (string)$off['id'] : '';
            $sku    = isset($off->vendorCode) ? (string)$off->vendorCode : $attrId;

            $pics = [];
            if (isset($off->picture)) foreach($off->picture as $p){
                $u=trim((string)$p); if($u!=='') $pics[]=$u;
            }
            $offers[] = ['sku'=>$sku, 'images'=>$pics];
        }
        $this->log('XML parsed: '.count($offers).' records.');
        return $offers;
    }

    private function build_offer_image_map($offers){
        $map=[];
        foreach($offers as $o){
            $sku = $this->normalize_sku((string)$o['sku']);
            if ($sku==='') continue;
            $urls = array_values(array_filter(array_map('trim', (array)$o['images'])));
            if (!empty($urls)) $map[$sku]=$urls;
        }
        $this->log('Build offer map: '.count($map).' SKU with images.');
        return $map;
    }

    private function normalize_sku($sku){
        return strtolower(trim($sku));
    }

    private function is_attachment_broken($att_id){
        $att_id = (int)$att_id;
        if ($att_id<=0) return true;
        
        $post = get_post($att_id);
        if (!$post || $post->post_type !== 'attachment') return true;
        
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) return true;
        if (!is_readable($file) || filesize($file) === 0) return true;
        
        $image_info = @getimagesize($file);
        if ($image_info === false) return true;
        
        return false;
    }

    private function is_product_images_broken($pid){
        $thumb = (int)get_post_meta($pid,'_thumbnail_id',true);
        if ($this->is_attachment_broken($thumb)) return true;
        if ($this->is_attachment_placeholder($thumb)) return true;
        
        $gal = get_post_meta($pid,'_product_image_gallery',true);
        if ($gal === '' || $gal === null) return false;
        
        $ids = array_filter(array_map('intval', explode(',',$gal)));
        if (empty($ids)) return false;
        
        foreach($ids as $aid){
            if ($this->is_attachment_broken($aid)) return true;
            if ($this->is_attachment_placeholder($aid)) return true;
        }
        
        return false;
    }

    /* ---------------- Utilities ---------------- */

    private function get_memory_limit(){
        $limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $limit, $m)) {
            $val = (int)$m[1];
            switch(strtoupper($m[2])){
                case 'G': return $val * 1024 * 1024 * 1024;
                case 'M': return $val * 1024 * 1024;
                case 'K': return $val * 1024;
            }
        }
        return 128 * 1024 * 1024;
    }

    private function is_attachment_placeholder($att_id){
        $att_id = (int)$att_id;
        if ($att_id <= 0) return true;
        $url = wp_get_attachment_url($att_id);
        if (!$url) return true;
        return $this->normalize_url($url) === $this->normalize_url(self::PLACEHOLDER_URL);
    }

    private function normalize_url($url){
        $url = trim((string)$url);
        if ($url === '') return '';
        return preg_replace('~^https?://~', '', $url);
    }

    private function convert_attachment_to_webp($att_id){
        $att_id = (int)$att_id;
        if ($att_id <= 0) return '';

        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) return '';

        $mime = get_post_mime_type($att_id);
        if ($mime === 'image/webp') return $file;

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            $this->log("  ✗ WebP editor error for attachment {$att_id}: ".$editor->get_error_message());
            return '';
        }

        $pathinfo = pathinfo($file);
        $webp_path = $pathinfo['dirname'].'/'.$pathinfo['filename'].'.webp';
        $saved = $editor->save($webp_path, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path'])) {
            if (is_wp_error($saved)) {
                $this->log("  ✗ WebP save error for attachment {$att_id}: ".$saved->get_error_message());
            }
            return '';
        }

        update_attached_file($att_id, $saved['path']);
        wp_update_post([
            'ID' => $att_id,
            'post_mime_type' => 'image/webp',
        ]);

        if ($file !== $saved['path'] && file_exists($file)) {
            @unlink($file);
        }

        $this->log("  → Converted attachment {$att_id} to WebP");
        return $saved['path'];
    }

    // ВИДАЛЕНО spawn_runner_async() повністю

    private function log($message){
        $line = '['.date_i18n('Y-m-d H:i:s').'] '.$message;
        $log = get_option(self::LOG_KEY);
        if(!is_array($log)) $log=[];
        $log[]=$line;
        if(count($log)>self::LOG_LIMIT) $log=array_slice($log,-self::LOG_LIMIT);
        update_option(self::LOG_KEY,$log,false);
    }
}

new Woo_Image_Fixer();
