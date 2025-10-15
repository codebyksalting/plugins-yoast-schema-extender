<?php
/**
 * Plugin Name: Yoast Schema Extender — Agency Pack (UI + Compatibility)
 * Description: Adds industry-aware, LLM-friendly schema on top of Yoast with a settings UI. Merges with Yoast Site Representation by default, with optional override. Skips LocalBusiness enrichment if Yoast Local SEO is active.
 * Version: 1.2.0
 * Author: Your Agency
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class YSE_Agency_UI {
    const OPT_KEY = 'yse_settings';
    private static $instance = null;

    public static function instance(){
        return self::$instance ?: self::$instance = new self();
    }

    private function __construct(){
        // Settings UI
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_enqueue_scripts', [$this,'admin_assets']);

        // Notices
        add_action('admin_notices', [$this,'maybe_show_dependency_notice']);

        // Hook schema filters only if Yoast is present
        add_action('plugins_loaded', function(){
            if ($this->yoast_available()){
                $this->hook_schema_filters();
            }
        });
    }

    /* ------------------------
     * Dependency helpers
     * ----------------------*/
    private function yoast_available(){
        return class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece');
    }
    private function yoast_local_available(){
        return defined('WPSEO_LOCAL_VERSION') || class_exists('\Yoast\WP\Local\Main');
    }

    public function maybe_show_dependency_notice(){
        if (!current_user_can('manage_options')) return;
        if (!$this->yoast_available()){
            echo '<div class="notice notice-error"><p><strong>Yoast Schema Extender:</strong> Yoast SEO not detected. Install & activate Yoast SEO to enable schema extensions.</p></div>';
        }
    }

    /* ------------------------
     * Settings UI
     * ----------------------*/
    public function admin_menu(){
        add_options_page(
            'Schema Extender',
            'Schema Extender',
            'manage_options',
            'yse-settings',
            [$this,'render_settings_page']
        );
    }

    public function admin_assets($hook){
        if ($hook !== 'settings_page_yse-settings') return;
        wp_enqueue_media();
        wp_enqueue_script('yse-admin', plugin_dir_url(__FILE__).'yse-admin.js', ['jquery'], '1.2.0', true);
        wp_add_inline_script('yse-admin', "
            jQuery(function($){
                $('.yse-media').on('click', function(e){
                    e.preventDefault();
                    const field = $('#'+$(this).data('target'));
                    const frame = wp.media({title: 'Select Logo', button:{text:'Use this'}, multiple:false});
                    frame.on('select', function(){
                        const att = frame.state().get('selection').first().toJSON();
                        field.val(att.url);
                    });
                    frame.open();
                });
            });
        ");
        wp_enqueue_style('yse-admin-css', plugin_dir_url(__FILE__).'yse-admin.css', [], '1.2.0');
        wp_add_inline_style('yse-admin-css', "
            .yse-field { margin: 12px 0; }
            .yse-field label { font-weight: 600; display:block; margin-bottom:4px; }
            .yse-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .yse-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
            .yse-note { color:#555; font-size:12px; }
            .yse-badge { display:inline-block; padding:2px 6px; background:#f0f0f1; border-radius:4px; font-size:11px; }
            .yse-status { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px; }
            .yse-status table { width:100%; border-collapse: collapse; }
            .yse-status th, .yse-status td { text-align:left; border-bottom:1px solid #efefef; padding:6px 8px; }
            .yse-good { color:#0a7; font-weight:600; }
            .yse-warn { color:#d60; font-weight:600; }
        ");
    }

    public function register_settings(){
        register_setting(self::OPT_KEY, self::OPT_KEY, [$this,'sanitize_settings']);

        // Main org/local section
        add_settings_section('yse_main', 'Organization & LocalBusiness', function(){
            echo '<p>Configure your primary entity for the Knowledge Graph and Local SEO. By default, values are merged with Yoast Site Representation.</p>';
        }, 'yse-settings');

        $fields = [
            ['org_name','Organization Name','text'],
            ['org_url','Organization URL','url'],
            ['org_logo','Logo URL','text'],
            ['same_as','sameAs Profiles (one URL per line)','textarea'],
            ['identifier','Identifiers (JSON array)','textarea'],
            ['is_local','Is LocalBusiness?','checkbox'],
            ['lb_subtype','LocalBusiness Subtype','select', self::local_subtypes()],
            // Address
            ['addr_street','Street Address','text'],
            ['addr_city','City / Locality','text'],
            ['addr_region','Region / State','text'],
            ['addr_postal','Postal Code','text'],
            ['addr_country','Country Code (e.g., US)','text'],
            ['telephone','Telephone (E.164 preferred)','text'],
            ['geo_lat','Geo Latitude','text'],
            ['geo_lng','Geo Longitude','text'],
            ['opening_hours','Opening Hours (JSON array of OpeningHoursSpecification)','textarea'],
        ];
        foreach ($fields as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_main', ['key'=>$f[0],'type'=>$f[2],'options'=>$f[3]??null]);
        }

        // Page intent
        add_settings_section('yse_intent', 'Page Intent Detection', function(){
            echo '<p>Adjust how we mark pages (ContactPage, AboutPage, FAQPage, HowTo). First match wins.</p>';
        }, 'yse-settings');
        foreach ([['slug_about','About slug (e.g., about)'],['slug_contact','Contact slug (e.g., contact)'],['faq_shortcode','FAQ shortcode tag (e.g., faq)'],['howto_shortcode','HowTo shortcode tag (e.g., howto)'],['extra_faq_slug','Additional FAQ page slug']] as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_intent', ['key'=>$f[0],'type'=>'text']);
        }

        // CPT map
        add_settings_section('yse_cpt', 'CPT → Schema Mapping', function(){
            echo '<p>One per line, format: <span class="yse-badge">cpt:Type</span> (e.g., <code>services:Service</code>, <code>locations:Place</code>, <code>team:Person</code>, <code>software:SoftwareApplication</code>).</p>';
        }, 'yse-settings');
        add_settings_field('cpt_map','Mappings',[$this,'render_field'],'yse-settings','yse_cpt',['key'=>'cpt_map','type'=>'textarea']);

        // Woo
        add_settings_section('yse_wc', 'WooCommerce', function(){
            echo '<p>Enhance Product schema with offers, price and availability.</p>';
        }, 'yse-settings');
        add_settings_field('wc_enable','Enable Woo Product augmentation',[$this,'render_field'],'yse-settings','yse_wc',['key'=>'wc_enable','type'=>'checkbox']);

        // Mentions
        add_settings_section('yse_mentions', 'Topic Mentions (LLM-friendly)', function(){
            echo '<p>Entities the site is clearly about/mentions. JSON array of <code>{ "@id": "https://..." }</code>.</p>';
        }, 'yse-settings');
        add_settings_field('entity_mentions','about/mentions JSON',[$this,'render_field'],'yse-settings','yse_mentions',['key'=>'entity_mentions','type'=>'textarea']);

        // Compatibility (Respect Yoast / Override toggle)
        add_settings_section('yse_overrides','Compatibility', function(){
            echo '<p>By default, the extender <strong>merges</strong> with Yoast Site Representation (fills blanks, merges sameAs). Turn on override to let your values take precedence.</p>';
        }, 'yse-settings');
        add_settings_field('override_org','Allow overriding Yoast Site Representation',[$this,'render_field'],'yse-settings','yse_overrides',['key'=>'override_org','type'=>'checkbox']);
    }

    public function render_field($args){
        $key = $args['key']; $type = $args['type']; $options = $args['options'] ?? null;
        $opt = get_option(self::OPT_KEY, []);
        $val = isset($opt[$key]) ? $opt[$key] : '';
        echo '<div class="yse-field">';
        if ($type==='text' || $type==='url'){
            printf('<input type="%s" class="regular-text" name="%s[%s]" id="%s" value="%s"/>',
                esc_attr($type), esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_attr($val));
            if ($key==='org_logo'){
                echo ' <button class="button yse-media" data-target="'.esc_attr($key).'">Select</button>';
                echo '<p class="yse-note">Recommended: transparent PNG/SVG; ideally the same logo used in Yoast.</p>';
            }
        } elseif ($type==='textarea'){
            printf('<textarea class="large-text code yse-mono" rows="6" name="%s[%s]" id="%s">%s</textarea>',
                esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_textarea($val));
        } elseif ($type==='checkbox'){
            printf('<label><input type="checkbox" name="%s[%s]" value="1" %s/> Enable</label>',
                esc_attr(self::OPT_KEY), esc_attr($key), checked($val, '1', false));
        } elseif ($type==='select'){
            echo '<select name="'.esc_attr(self::OPT_KEY).'['.esc_attr($key).']" id="'.esc_attr($key).'">';
            echo '<option value="">(Default)</option>';
            foreach ($options as $k=>$label){
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
            }
            echo '</select>';
        }
        echo '</div>';
    }

    public function render_settings_page(){
        $yoast_ok     = $this->yoast_available();
        $yoast_local  = $this->yoast_local_available();
        $status_table = $this->gather_org_status_rows();
        ?>
        <div class="wrap">
            <h1>Schema Extender</h1>
            <?php if(!$yoast_ok): ?>
                <div class="notice notice-warning"><p>Yoast SEO not detected. The schema extensions will be inactive until Yoast SEO is active.</p></div>
            <?php endif; ?>
            <?php if($yoast_local): ?>
                <div class="notice notice-info"><p>Yoast Local SEO detected. LocalBusiness enrichment from the extender will be minimized to avoid duplication.</p></div>
            <?php endif; ?>

            <h2>Current Site Representation Status</h2>
            <div class="yse-status">
                <table>
                    <thead><tr><th>Field</th><th>Yoast Value</th><th>Extender Value</th><th>Effective</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php foreach ($status_table as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['field']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['yoast']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['ext']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['effective']); ?></td>
                            <td><?php echo $row['source'] === 'yoast' ? '<span class="yse-good">Yoast</span>' : ($row['source']==='ext' ? '<span class="yse-warn">Extender</span>' : 'Merged'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="yse-note">“Source” shows where the effective value is coming from right now (respecting your override setting).</p>
            </div>

            <form method="post" action="options.php" style="margin-top:16px;">
                <?php
                settings_fields(self::OPT_KEY);
                do_settings_sections('yse-settings');
                submit_button('Save Settings');
                ?>
            </form>

            <hr/>
            <p class="yse-note">Validate with Google Rich Results Test and validator.schema.org. Keep markup honest with visible content.</p>
        </div>
        <?php
    }

    private function gather_org_status_rows(){
        // Pull Yoast Site Representation (best-effort via filters/graph)
        $yoast_name = get_bloginfo('name');
        $yoast_url  = home_url('/');
        $yoast_logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';

        // Our settings
        $s = get_option(self::OPT_KEY, []);
        $override = !empty($s['override_org']) && $s['override_org']==='1';

        $ext_name = $s['org_name'] ?? '';
        $ext_url  = $s['org_url'] ?? '';
        $ext_logo = $s['org_logo'] ?? '';

        $rows = [];

        // Effective values (respect mode or override)
        $eff_name = $override ? ($ext_name ?: $yoast_name) : ($yoast_name ?: $ext_name);
        $eff_url  = $override ? ($ext_url  ?: $yoast_url ) : ($yoast_url  ?: $ext_url );
        $eff_logo = $override ? ($ext_logo ?: $yoast_logo) : ($yoast_logo ?: $ext_logo);

        $rows[] = [
            'field'=>'name',
            'yoast'=>$yoast_name,
            'ext'=>$ext_name,
            'effective'=>$eff_name,
            'source'=> ($override ? ( $ext_name ? 'ext':'yoast') : ( $yoast_name ? 'yoast':'ext')),
        ];
        $rows[] = [
            'field'=>'url',
            'yoast'=>$yoast_url,
            'ext'=>$ext_url,
            'effective'=>$eff_url,
            'source'=> ($override ? ( $ext_url ? 'ext':'yoast') : ( $yoast_url ? 'yoast':'ext')),
        ];
        $rows[] = [
            'field'=>'logo',
            'yoast'=>$yoast_logo,
            'ext'=>$ext_logo,
            'effective'=>$eff_logo,
            'source'=> ($override ? ( $ext_logo ? 'ext':'yoast') : ( $yoast_logo ? 'yoast':'ext')),
        ];

        return $rows;
    }

    public function sanitize_settings($in){
        $out = [];
        // Basic
        $out['org_name']  = sanitize_text_field($in['org_name'] ?? '');
        $out['org_url']   = esc_url_raw($in['org_url'] ?? '');
        $out['org_logo']  = esc_url_raw($in['org_logo'] ?? '');
        $out['same_as']   = $this->sanitize_lines_as_urls($in['same_as'] ?? '');
        $out['identifier']= $this->sanitize_json($in['identifier'] ?? '[]', []);
        $out['is_local']  = !empty($in['is_local']) ? '1' : '0';
        $out['lb_subtype']= sanitize_text_field($in['lb_subtype'] ?? '');
        // Address
        $out['addr_street']  = sanitize_text_field($in['addr_street'] ?? '');
        $out['addr_city']    = sanitize_text_field($in['addr_city'] ?? '');
        $out['addr_region']  = sanitize_text_field($in['addr_region'] ?? '');
        $out['addr_postal']  = sanitize_text_field($in['addr_postal'] ?? '');
        $out['addr_country'] = sanitize_text_field($in['addr_country'] ?? '');
        $out['telephone']    = sanitize_text_field($in['telephone'] ?? '');
        $out['geo_lat']      = sanitize_text_field($in['geo_lat'] ?? '');
        $out['geo_lng']      = sanitize_text_field($in['geo_lng'] ?? '');
        $out['opening_hours']= $this->sanitize_json($in['opening_hours'] ?? '[]', []);
        // Intent
        $out['slug_about']     = sanitize_title($in['slug_about'] ?? '');
        $out['slug_contact']   = sanitize_title($in['slug_contact'] ?? '');
        $out['faq_shortcode']  = sanitize_key($in['faq_shortcode'] ?? '');
        $out['howto_shortcode']= sanitize_key($in['howto_shortcode'] ?? '');
        $out['extra_faq_slug'] = sanitize_title($in['extra_faq_slug'] ?? '');
        // CPT map
        $out['cpt_map'] = $this->sanitize_cpt_map($in['cpt_map'] ?? '');
        // Woo
        $out['wc_enable'] = !empty($in['wc_enable']) ? '1' : '0';
        // Mentions
        $out['entity_mentions'] = $this->sanitize_json($in['entity_mentions'] ?? '[]', []);
        // Overrides
        $out['override_org'] = !empty($in['override_org']) ? '1' : '0';
        return $out;
    }

    private function sanitize_lines_as_urls($raw){
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$raw)));
        $urls  = [];
        foreach($lines as $l){
            $u = esc_url_raw($l);
            if ($u) $urls[] = $u;
        }
        return $urls;
    }

    private function sanitize_json($raw, $fallback){
        $raw = trim((string)$raw);
        if ($raw==='') return $fallback;
        $decoded = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $fallback;
    }

    private function sanitize_cpt_map($raw){
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$raw)));
        $map = [];
        foreach($lines as $line){
            if (strpos($line, ':') !== false){
                [$cpt, $type] = array_map('trim', explode(':', $line, 2));
                if ($cpt && $type){
                    $map[sanitize_key($cpt)] = preg_replace('/[^A-Za-z]/', '', $type);
                }
            }
        }
        return $map;
    }

    private static function local_subtypes(){
        return [
            'LocalBusiness'=>'LocalBusiness',
            'ProfessionalService'=>'ProfessionalService',
            'LegalService'=>'LegalService',
            'AccountingService'=>'AccountingService',
            'FinancialService'=>'FinancialService',
            'InsuranceAgency'=>'InsuranceAgency',
            'RealEstateAgent'=>'RealEstateAgent',
            'MedicalOrganization'=>'MedicalOrganization',
            'Dentist'=>'Dentist',
            'Physician'=>'Physician',
            'Pharmacy'=>'Pharmacy',
            'VeterinaryCare'=>'VeterinaryCare',
            'HealthAndBeautyBusiness'=>'HealthAndBeautyBusiness',
            'HairSalon'=>'HairSalon',
            'NailSalon'=>'NailSalon',
            'DaySpa'=>'DaySpa',
            'HomeAndConstructionBusiness'=>'HomeAndConstructionBusiness',
            'Electrician'=>'Electrician',
            'HVACBusiness'=>'HVACBusiness',
            'Locksmith'=>'Locksmith',
            'MovingCompany'=>'MovingCompany',
            'Plumber'=>'Plumber',
            'RoofingContractor'=>'RoofingContractor',
            'GeneralContractor'=>'GeneralContractor',
            'AutomotiveBusiness'=>'AutomotiveBusiness',
            'AutoRepair'=>'AutoRepair',
            'AutoBodyShop'=>'AutoBodyShop',
            'AutoDealer'=>'AutoDealer',
            'FoodEstablishment'=>'FoodEstablishment',
            'Restaurant'=>'Restaurant',
            'Bakery'=>'Bakery',
            'CafeOrCoffeeShop'=>'CafeOrCoffeeShop',
            'BarOrPub'=>'BarOrPub',
            'LodgingBusiness'=>'LodgingBusiness',
            'Hotel'=>'Hotel',
            'Motel'=>'Motel',
            'Resort'=>'Resort',
            'Store'=>'Store',
            'BookStore'=>'BookStore',
            'ClothingStore'=>'ClothingStore',
            'ComputerStore'=>'ComputerStore',
            'ElectronicsStore'=>'ElectronicsStore',
            'FurnitureStore'=>'FurnitureStore',
            'GardenStore'=>'GardenStore',
            'GroceryStore'=>'GroceryStore',
            'HardwareStore'=>'HardwareStore',
            'JewelryStore'=>'JewelryStore',
            'MobilePhoneStore'=>'MobilePhoneStore',
            'SportingGoodsStore'=>'SportingGoodsStore',
            'TireShop'=>'TireShop',
            'ToyStore'=>'ToyStore',
            'Gym'=>'Gym',
            'HealthClub'=>'HealthClub',
        ];
    }

    /* ------------------------
     * Schema Filters (merge-first, override optional)
     * ----------------------*/
    private function hook_schema_filters(){
        // Organization / LocalBusiness (merge-first)
        add_filter('wpseo_schema_organization', function($data){
            $s = get_option(self::OPT_KEY, []);
            $yoast_local_active = $this->yoast_local_available();
            $override = !empty($s['override_org']) && $s['override_org'] === '1';

            // Determine locality
            $is_local = (!$yoast_local_active) && !empty($s['is_local']) && $s['is_local'] === '1';

            // @type / @id: avoid clobbering Yoast types; only override if asked
            if ($override) {
                $data['@type'] = $is_local ? (!empty($s['lb_subtype']) ? $s['lb_subtype'] : 'LocalBusiness') : 'Organization';
            }
            if (empty($data['@id'])) {
                $data['@id'] = $is_local ? home_url('#/schema/localbusiness') : home_url('#/schema/organization');
            }

            // Name / URL / Logo (fill blanks unless overriding)
            if ($override || empty($data['name'])) $data['name'] = !empty($s['org_name']) ? $s['org_name'] : ($data['name'] ?? get_bloginfo('name'));
            if ($override || empty($data['url']))  $data['url']  = !empty($s['org_url'])  ? $s['org_url']  : ($data['url']  ?? home_url('/'));
            if (!empty($s['org_logo']) && ($override || empty($data['logo']))) $data['logo'] = ['@type'=>'ImageObject','url'=>$s['org_logo']];

            // sameAs: union + de-dupe
            $existing_sameas = isset($data['sameAs']) && is_array($data['sameAs']) ? $data['sameAs'] : [];
            $ours_sameas     = !empty($s['same_as']) ? (array)$s['same_as'] : [];
            $data['sameAs']  = array_values(array_unique(array_filter(array_merge($existing_sameas, $ours_sameas))));

            // Identifiers: merge arrays
            if (!empty($s['identifier']) && is_array($s['identifier'])) {
                $data['identifier'] = array_values(array_merge($data['identifier'] ?? [], $s['identifier']));
            }

            // LocalBusiness enrichment (skip if Yoast Local active)
            if ($is_local && !$yoast_local_active) {
                $addr = array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $s['addr_street'] ?? '',
                    'addressLocality' => $s['addr_city'] ?? '',
                    'addressRegion'   => $s['addr_region'] ?? '',
                    'postalCode'      => $s['addr_postal'] ?? '',
                    'addressCountry'  => $s['addr_country'] ?? '',
                ]);
                if (!empty($addr['streetAddress'])) {
                    if ($override || empty($data['address'])) $data['address'] = $addr;
                }
                if (!empty($s['telephone']) && ($override || empty($data['telephone']))) $data['telephone'] = $s['telephone'];
                if (!empty($s['opening_hours']) && is_array($s['opening_hours']) && !empty($s['opening_hours'])) {
                    if ($override || empty($data['openingHoursSpecification'])) $data['openingHoursSpecification'] = $s['opening_hours'];
                }
                if (!empty($s['geo_lat']) && !empty($s['geo_lng'])) {
                    if ($override || empty($data['geo'])) {
                        $data['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$s['geo_lat'],'longitude'=>$s['geo_lng']];
                    }
                }
            }

            return $data;
        }, 20);

        // WebPage type tweaks + mentions
        add_filter('wpseo_schema_webpage', function($data){
            if (!is_singular()) return $data;
            $s = get_option(self::OPT_KEY, []);
            global $post;
            $slug = $post ? $post->post_name : '';
            $content = $post ? get_post_field('post_content', $post) : '';

            if (!empty($s['slug_about']) && $slug === $s['slug_about']){
                $data['@type'] = 'AboutPage';
            } elseif (!empty($s['slug_contact']) && $slug === $s['slug_contact']){
                $data['@type'] = 'ContactPage';
            } elseif (!empty($s['extra_faq_slug']) && $slug === $s['extra_faq_slug']){
                $data['@type'] = 'FAQPage';
            } else {
                if (!empty($s['faq_shortcode']) && function_exists('has_shortcode') && has_shortcode($content, $s['faq_shortcode'])){
                    $data['@type'] = 'FAQPage';
                } elseif (!empty($s['howto_shortcode']) && function_exists('has_shortcode') && has_shortcode($content, $s['howto_shortcode'])){
                    $data['@type'] = 'HowTo';
                }
            }

            if (!empty($s['entity_mentions']) && is_array($s['entity_mentions'])){
                $data['about']    = array_merge($data['about']    ?? [], $s['entity_mentions']);
                $data['mentions'] = array_merge($data['mentions'] ?? [], $s['entity_mentions']);
            }
            return $data;
        }, 20);

        // Graph additions (CPT → Schema, Woo Product, Breadcrumb, FAQ, Video)
        add_filter('wpseo_schema_graph_pieces', function($pieces, $context){
            if (!is_singular()) return $pieces;
            $s = get_option(self::OPT_KEY, []);
            global $post;

            // CPT map
            $map = is_array($s['cpt_map'] ?? null) ? $s['cpt_map'] : [];
            $ptype = $post ? get_post_type($post) : '';
            if ($ptype && isset($map[$ptype])){
                $type = preg_replace('/[^A-Za-z]/','', $map[$ptype]);
                if ($type){
                    $pieces[] = new class($context, $type, $post) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                        private $type; private $post;
                        public function __construct($context, $type, $post){ parent::__construct($context); $this->type=$type; $this->post=$post; }
                        public function is_needed(){ return true; }
                        public function generate(){
                            $id = get_permalink($this->post).'#/schema/'.strtolower($this->type);
                            $org_id = home_url('#/schema/organization');
                            $desc = wp_strip_all_tags(get_the_excerpt($this->post) ?: get_post_field('post_content',$this->post));
                            $graph = [
                                '@type'       => $this->type,
                                '@id'         => $id,
                                'name'        => get_the_title($this->post),
                                'url'         => get_permalink($this->post),
                                'description' => $desc ? wp_trim_words($desc, 60, '') : '',
                                'provider'    => ['@id'=>$org_id],
                            ];
                            if ($this->type === 'Place'){
                                $s = get_option('yse_settings', []);
                                $addr = array_filter([
                                    '@type'=>'PostalAddress',
                                    'streetAddress'=>$s['addr_street'] ?? '',
                                    'addressLocality'=>$s['addr_city'] ?? '',
                                    'addressRegion'=>$s['addr_region'] ?? '',
                                    'postalCode'=>$s['addr_postal'] ?? '',
                                    'addressCountry'=>$s['addr_country'] ?? '',
                                ]);
                                if (!empty($addr['streetAddress'])) $graph['address'] = $addr;
                                if (!empty($s['geo_lat']) && !empty($s['geo_lng'])){
                                    $graph['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$s['geo_lat'],'longitude'=>$s['geo_lng']];
                                }
                            }
                            return $graph;
                        }
                    };
                }
            }

            // WooCommerce Product augmentation
            if (function_exists('is_product') && is_product()){
                $enable = !empty($s['wc_enable']) && $s['wc_enable']==='1';
                if ($enable){
                    global $product;
                    if ($product instanceof WC_Product){
                        $pieces[] = new class($context, $product) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                            private $product;
                            public function __construct($context,$product){ parent::__construct($context); $this->product=$product; }
                            public function is_needed(){ return true; }
                            public function generate(){
                                $id = get_permalink($this->product->get_id()).'#/schema/product';
                                $price = function_exists('wc_get_price_to_display') ? wc_get_price_to_display($this->product) : $this->product->get_price();
                                $data = [
                                    '@type'=>'Product',
                                    '@id'=>$id,
                                    'name'=>$this->product->get_name(),
                                    'sku'=>$this->product->get_sku() ?: null,
                                    'url'=>get_permalink($this->product->get_id()),
                                    'description'=>wp_strip_all_tags($this->product->get_short_description() ?: $this->product->get_description()),
                                    'brand'=>['@type'=>'Brand','name'=>get_bloginfo('name')],
                                    'offers'=>[
                                        '@type'=>'Offer',
                                        'price'=>$price,
                                        'priceCurrency'=>get_woocommerce_currency(),
                                        'availability'=>$this->product->is_in_stock() ? 'http://schema.org/InStock' : 'http://schema.org/OutOfStock',
                                        'url'=>get_permalink($this->product->get_id()),
                                    ],
                                ];
                                return array_filter($data);
                            }
                        };
                    }
                }
            }

            // BreadcrumbList (simple two-level)
            $pieces[] = new class($context) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                public function is_needed(){ return true; }
                public function generate(){
                    $crumbs = [];
                    $pos = 1;
                    $crumbs[] = ['@type'=>'ListItem','position'=>$pos++,'item'=>['@id'=>home_url('/'),'name'=>get_bloginfo('name')]];
                    if (is_singular()){
                        $crumbs[] = ['@type'=>'ListItem','position'=>$pos++,'item'=>['@id'=>get_permalink(),'name'=>get_the_title()]];
                    }
                    return ['@type'=>'BreadcrumbList','@id'=>get_permalink().'#/schema/breadcrumb','itemListElement'=>$crumbs];
                }
            };

            // Lightweight FAQ auto-detect (visible H2/H3/H4 Q? + P answer)
            $content_full = $post ? apply_filters('the_content', $post->post_content) : '';
            if ($content_full && preg_match_all('/<h[2-4][^>]*>([^<\?]+)\?\s*<\/h[2-4]>\s*<p[^>]*>(.+?)<\/p>/si', $content_full, $m_qas)){
                if (count($m_qas[1]) >= 2){
                    $pieces[] = new class($context, $m_qas) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                        private $m;
                        public function __construct($context,$m){ parent::__construct($context); $this->m=$m; }
                        public function is_needed(){ return true; }
                        public function generate(){
                            $qs = $this->m[1]; $as = $this->m[2]; $items=[];
                            foreach($qs as $i=>$q){
                                $items[] = ['@type'=>'Question','name'=>wp_strip_all_tags($q.'?'),'acceptedAnswer'=>['@type'=>'Answer','text'=>wp_kses_post($as[$i] ?? '')]];
                            }
                            return ['@type'=>'FAQPage','@id'=>get_permalink().'#/schema/faq','mainEntity'=>$items];
                        }
                    };
                }
            }

            // VideoObject (YouTube/Vimeo detection)
            if ($content_full && preg_match('/<iframe[^>]+src="[^"]*(youtube|vimeo)\.com[^"]+"/i', $content_full)){
                $pieces[] = new class($context) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                    public function is_needed(){ return true; }
                    public function generate(){
                        return [
                            '@type'=>'VideoObject',
                            '@id'=>get_permalink().'#/schema/video',
                            'name'=>get_the_title(),
                            'description'=>wp_strip_all_tags(get_the_excerpt() ?: ''),
                            'thumbnailUrl'=>[],
                            'uploadDate'=>get_post_time('c', true),
                            'url'=>get_permalink(),
                        ];
                    }
                };
            }

            return $pieces;
        }, 20, 2);

        // Stronger Article linkage
        add_filter('wpseo_schema_article', function($data){
            $data['isPartOf'] = $data['isPartOf'] ?? ['@id' => home_url(add_query_arg([], $GLOBALS['wp']->request)).'#/schema/webpage'];
            $data['publisher'] = ['@id' => home_url('#/schema/organization')];
            return $data;
        }, 20);

        // Admin warning for LocalBusiness missing address
        add_action('admin_notices', function(){
            if (!current_user_can('manage_options')) return;
            $s = get_option(self::OPT_KEY, []);
            $yoast_local_active = $this->yoast_local_available();
            if (!empty($s['is_local']) && $s['is_local']==='1' && !$yoast_local_active){
                $addr_ok = !empty($s['addr_street']);
                if (!$addr_ok){
                    echo '<div class="notice notice-warning"><p><strong>Schema Extender:</strong> LocalBusiness is enabled but the street address is empty. Fill address under <em>Settings → Schema Extender</em>.</p></div>';
                }
            }
        });
    }
}

YSE_Agency_UI::instance();
