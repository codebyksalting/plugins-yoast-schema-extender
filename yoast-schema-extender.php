<?php
/**
 * Plugin Name: Yoast Schema Extender — Agency Pack (UI + Compatibility, No Woo)
 * Description: Adds industry-aware, LLM-friendly schema on top of Yoast with a settings UI and per-post overrides. Respects Yoast Site Representation by default (merge-first) with optional override. Skips LocalBusiness enrichment if Yoast Local SEO is active. Includes ELI5 help, example-fillers, JSON validation with useful error messages.
 * Version: 1.4.0
 * Author: Thomas Digital
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
        // Admin UI
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_enqueue_scripts', [$this,'admin_assets']);
        add_action('admin_notices', [$this,'maybe_show_dependency_notice']);

        // Per-post overrides
        add_action('add_meta_boxes', [$this,'add_metabox']);
        add_action('save_post', [$this,'save_metabox'], 10, 2);

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
        add_options_page('Schema Extender','Schema Extender','manage_options','yse-settings',[$this,'render_settings_page']);
    }

    public function admin_assets($hook){
        if ($hook !== 'settings_page_yse-settings' && $hook !== 'post.php' && $hook !== 'post-new.php') return;
        wp_enqueue_media();
        wp_enqueue_script('yse-admin', plugin_dir_url(__FILE__).'yse-admin.js', ['jquery'], '1.4.0', true);
        wp_add_inline_script('yse-admin', "
            jQuery(function($){
                // Media picker
                $('.yse-media').on('click', function(e){
                    e.preventDefault();
                    const field = $('#'+$(this).data('target'));
                    const frame = wp.media({title:'Select Logo', button:{text:'Use this'}, multiple:false});
                    frame.on('select', function(){
                        const att = frame.state().get('selection').first().toJSON();
                        field.val(att.url);
                    });
                    frame.open();
                });
                // Example fillers for JSON fields
                function fill(id, sample){
                    const el = $('#'+id);
                    el.val(JSON.stringify(sample, null, 2));
                }
                $('[data-yse-example=\"identifier\"]').on('click', function(e){
                    e.preventDefault();
                    fill('identifier', [{\"@type\":\"PropertyValue\",\"propertyID\":\"DUNS\",\"value\":\"123456789\"}]);
                });
                $('[data-yse-example=\"opening_hours\"]').on('click', function(e){
                    e.preventDefault();
                    fill('opening_hours', [
                        {\"@type\":\"OpeningHoursSpecification\",\"dayOfWeek\":[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"],\"opens\":\"09:00\",\"closes\":\"17:00\"}
                    ]);
                });
                $('[data-yse-example=\"entity_mentions\"]').on('click', function(e){
                    e.preventDefault();
                    fill('entity_mentions', [
                        {\"@id\":\"https://en.wikipedia.org/wiki/Web_design\"},
                        {\"@id\":\"https://www.wikidata.org/wiki/Q16674915\"}
                    ]);
                });
            });
        ");
        wp_enqueue_style('yse-admin-css', plugin_dir_url(__FILE__).'yse-admin.css', [], '1.4.0');
        wp_add_inline_style('yse-admin-css', "
            .yse-field { margin: 12px 0; }
            .yse-field label { font-weight: 600; display:block; margin-bottom:4px; }
            .yse-help { color:#555; font-size:12px; margin-top:4px; }
            .yse-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .yse-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
            .yse-badge { display:inline-block; padding:2px 6px; background:#f0f0f1; border-radius:4px; font-size:11px; }
            .yse-status { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px; }
            .yse-status table { width:100%; border-collapse: collapse; }
            .yse-status th, .yse-status td { text-align:left; border-bottom:1px solid #efefef; padding:6px 8px; }
            .yse-good { color:#0a7; font-weight:600; }
            .yse-warn { color:#d60; font-weight:600; }
            .button-link { margin-left:8px; }
            textarea.code { min-height: 140px; }
            .yse-meta small { color:#666; display:block; margin-top:4px; }
        ");
    }

    public function register_settings(){
        register_setting(self::OPT_KEY, self::OPT_KEY, [$this,'sanitize_settings']);

        // Main org/local section
        add_settings_section('yse_main', 'Organization & LocalBusiness', function(){
            echo '<p>Configure your primary entity for the Knowledge Graph and Local SEO. By default, values are merged with Yoast Site Representation (we fill blanks and merge lists).</p>';
        }, 'yse-settings');

        $fields = [
            ['org_name','Organization Name','text','', 'The official business name (as customers see it).'],
            ['org_url','Organization URL','url','', 'Your primary website address (home page).'],
            ['org_logo','Logo URL','text','', 'Square or rectangular logo. Use the “Select” button to pick from Media Library.'],
            ['same_as','sameAs Profiles (one URL per line)','textarea','', 'ELI5: Paste links to your official profiles (LinkedIn, Facebook, Crunchbase, etc.). One per line. We merge these with Yoast’s list.'],
            ['identifier','Identifiers (JSON array)','textarea','identifier', 'ELI5: Extra IDs that prove who you are (optional). Example → click “Insert example”.'],
            ['is_local','Is LocalBusiness?','checkbox','', 'Tick if you serve a local area or have a location customers visit.'],
            ['lb_subtype','LocalBusiness Subtype','select', self::local_subtypes(), 'Pick the closest match to your business type.'],
            // Address
            ['addr_street','Street Address','text','', 'Street and unit/suite. Leave blank if not public.'],
            ['addr_city','City / Locality','text','', 'City name (e.g., Sacramento).'],
            ['addr_region','Region / State','text','', 'State/region (e.g., CA).'],
            ['addr_postal','Postal Code','text','', 'ZIP or postal code.'],
            ['addr_country','Country Code (e.g., US)','text','', 'Two-letter country code (ISO-3166).'],
            ['telephone','Telephone (E.164 preferred)','text','', 'Phone number (e.g., +1-916-555-1212).'],
            ['geo_lat','Geo Latitude','text','', 'Optional. Decimal latitude (e.g., 38.5816).'],
            ['geo_lng','Geo Longitude','text','', 'Optional. Decimal longitude (e.g., -121.4944).'],
            ['opening_hours','Opening Hours (JSON array)','textarea','opening_hours', 'ELI5: Your business hours. Example → click “Insert example”.'],
        ];
        foreach ($fields as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_main', [
                'key'=>$f[0],'type'=>$f[2],'options'=>$f[3]??null,'help'=>$f[4]??''
            ]);
        }

        // Page intent
        add_settings_section('yse_intent', 'Page Intent Detection', function(){
            echo '<p>Tell search engines what a page <em>is</em>. First match wins. Keep it honest: only label pages that actually show that content type.</p>';
        }, 'yse-settings');
        $intent = [
            ['slug_about','About slug (e.g., about)','text','', 'If your About page slug is /about-us, enter <code>about-us</code>.'],
            ['slug_contact','Contact slug (e.g., contact)','text','', 'If your Contact page slug is /get-in-touch, enter <code>get-in-touch</code>.'],
            ['faq_shortcode','FAQ shortcode tag (e.g., faq)','text','', 'If your FAQ uses a shortcode like [faq], enter <code>faq</code>.'],
            ['howto_shortcode','HowTo shortcode tag (e.g., howto)','text','', 'If your how-to uses [howto], enter <code>howto</code>.'],
            ['extra_faq_slug','Additional FAQ page slug','text','', 'If you have a separate FAQs page, enter its slug here (e.g., <code>faqs</code>).'],
        ];
        foreach ($intent as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_intent', [
                'key'=>$f[0],'type'=>$f[2],'help'=>$f[4]??''
            ]);
        }

        // CPT map
        add_settings_section('yse_cpt', 'CPT → Schema Mapping', function(){
            echo '<p>One per line, format: <span class="yse-badge">cpt:Type</span> (e.g., <code>services:Service</code>, <code>locations:Place</code>, <code>team:Person</code>, <code>software:SoftwareApplication</code>).</p>
            <p class="yse-help">ELI5: You tell us what each custom post type represents. Example:<br><code>services:Service</code><br><code>locations:Place</code><br><code>team:Person</code></p>';
        }, 'yse-settings');
        add_settings_field('cpt_map','Mappings',[$this,'render_field'],'yse-settings','yse_cpt',['key'=>'cpt_map','type'=>'textarea','help'=>'Enter one mapping per line.']);

        // Mentions
        add_settings_section('yse_mentions', 'Topic Mentions (LLM-friendly)', function(){
            echo '<p>Entities the site is clearly about/mentions. JSON array of <code>{ "@id": "https://..." }</code>.</p>
                  <p class="yse-help">ELI5: Think of these as Wikipedia/Wikidata links that describe your topics. Example → click “Insert example”.</p>';
        }, 'yse-settings');
        add_settings_field('entity_mentions','about/mentions JSON',[$this,'render_field'],'yse-settings','yse_mentions',[
            'key'=>'entity_mentions','type'=>'textarea','options'=>'entity_mentions','help'=>'Keep it short and relevant. We add these to both about and mentions.'
        ]);

        // Compatibility (Respect Yoast / Override toggle)
        add_settings_section('yse_overrides','Compatibility', function(){
            echo '<p>By default, we <strong>merge</strong> with Yoast Site Representation (fill blanks, merge lists). Turn on override to let your values win.</p>';
        }, 'yse-settings');
        add_settings_field('override_org','Allow overriding Yoast Site Representation',[$this,'render_field'],'yse-settings','yse_overrides',['key'=>'override_org','type'=>'checkbox','help'=>'Leave OFF unless Yoast values are missing/wrong.']);
    }

    public function render_field($args){
        $key = $args['key']; $type = $args['type']; $options = $args['options'] ?? null; $help = $args['help'] ?? '';
        $opt = get_option(self::OPT_KEY, []);
        $val = isset($opt[$key]) ? $opt[$key] : '';

        echo '<div class="yse-field">';
        if ($type==='text' || $type==='url'){
            printf('<input type="%s" class="regular-text" name="%s[%s]" id="%s" value="%s"/>',
                esc_attr($type), esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_attr($val));
            if ($key==='org_logo'){
                echo ' <button class="button yse-media" data-target="'.esc_attr($key).'">Select</button>';
            }
        } elseif ($type==='textarea'){
            printf('<textarea class="large-text code yse-mono" rows="8" name="%s[%s]" id="%s">%s</textarea>',
                esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_textarea(is_array($val)?json_encode($val,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES):$val));
            // JSON helpers
            if ($key==='identifier'){
                echo ' <a href="#" class="button-link" data-yse-example="identifier">Insert example</a>';
                echo '<div class="yse-help">Format: JSON array. Example:<br><code>[
  { \"@type\": \"PropertyValue\", \"propertyID\": \"DUNS\", \"value\": \"123456789\" }
]</code><br>Translation: “We’re adding official IDs (like DUNS). Optional.”</div>';
            }
            if ($key==='opening_hours'){
                echo ' <a href="#" class="button-link" data-yse-example="opening_hours">Insert example</a>';
                echo '<div class="yse-help">Format: JSON array of <code>OpeningHoursSpecification</code>. Example:<br><code>[
  { \"@type\": \"OpeningHoursSpecification\",
    \"dayOfWeek\": [\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"],
    \"opens\": \"09:00\", \"closes\": \"17:00\" }
]</code><br>Translation: “List the days you’re open and the times. Use 24-hour HH:MM.”</div>';
            }
            if ($key==='entity_mentions'){
                echo ' <a href="#" class="button-link" data-yse-example="entity_mentions">Insert example</a>';
                echo '<div class="yse-help">Format: JSON array of objects with <code>@id</code> URLs. Example:<br><code>[
  { \"@id\": \"https://en.wikipedia.org/wiki/Web_design\" },
  { \"@id\": \"https://www.wikidata.org/wiki/Q16674915\" }
]</code><br>Translation: “Authoritative pages that define your topic. Keep it short.”</div>';
            }
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
        if (!empty($help)){
            echo '<div class="yse-help">'.$help.'</div>';
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
            <?php settings_errors(self::OPT_KEY); ?>
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
                <p class="yse-help">“Source” shows where the effective value is coming from right now (respecting your override setting).</p>
            </div>

            <form method="post" action="options.php" style="margin-top:16px;">
                <?php
                settings_fields(self::OPT_KEY);
                do_settings_sections('yse-settings');
                submit_button('Save Settings');
                ?>
            </form>

            <hr/>
            <p class="yse-help">Validate with Google Rich Results Test and validator.schema.org. Keep markup honest with visible content.</p>
        </div>
        <?php
    }

    private function gather_org_status_rows(){
        $yoast_name = get_bloginfo('name');
        $yoast_url  = home_url('/');
        $yoast_logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';

        $s = get_option(self::OPT_KEY, []);
        $override = !empty($s['override_org']) && $s['override_org']==='1';

        $ext_name = $s['org_name'] ?? '';
        $ext_url  = $s['org_url'] ?? '';
        $ext_logo = $s['org_logo'] ?? '';

        $rows = [];
        $eff_name = $override ? ($ext_name ?: $yoast_name) : ($yoast_name ?: $ext_name);
        $eff_url  = $override ? ($ext_url  ?: $yoast_url ) : ($yoast_url  ?: $ext_url );
        $eff_logo = $override ? ($ext_logo ?: $yoast_logo) : ($yoast_logo ?: $ext_logo);

        $rows[] = ['field'=>'name','yoast'=>$yoast_name,'ext'=>$ext_name,'effective'=>$eff_name,'source'=> ($override ? ( $ext_name ? 'ext':'yoast') : ( $yoast_name ? 'yoast':'ext'))];
        $rows[] = ['field'=>'url','yoast'=>$yoast_url,'ext'=>$ext_url,'effective'=>$eff_url,'source'=> ($override ? ( $ext_url ? 'ext':'yoast') : ( $yoast_url ? 'yoast':'ext'))];
        $rows[] = ['field'=>'logo','yoast'=>$yoast_logo,'ext'=>$ext_logo,'effective'=>$eff_logo,'source'=> ($override ? ( $ext_logo ? 'ext':'yoast') : ( $yoast_logo ? 'yoast':'ext'))];

        return $rows;
    }

    public function sanitize_settings($in){
        $out = [];
        // Basic
        $out['org_name']  = sanitize_text_field($in['org_name'] ?? '');
        $out['org_url']   = esc_url_raw($in['org_url'] ?? '');
        $out['org_logo']  = esc_url_raw($in['org_logo'] ?? '');
        $out['same_as']   = $this->sanitize_lines_as_urls($in['same_as'] ?? '');
        $out['identifier']= $this->sanitize_json_field($in['identifier'] ?? '[]', [], 'identifier', 'Identifiers');
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
        $out['opening_hours']= $this->sanitize_json_field($in['opening_hours'] ?? '[]', [], 'opening_hours', 'Opening Hours');
        // Intent
        $out['slug_about']     = sanitize_title($in['slug_about'] ?? '');
        $out['slug_contact']   = sanitize_title($in['slug_contact'] ?? '');
        $out['faq_shortcode']  = sanitize_key($in['faq_shortcode'] ?? '');
        $out['howto_shortcode']= sanitize_key($in['howto_shortcode'] ?? '');
        $out['extra_faq_slug'] = sanitize_title($in['extra_faq_slug'] ?? '');
        // CPT map
        $out['cpt_map'] = $this->sanitize_cpt_map($in['cpt_map'] ?? '');
        // Mentions
        $out['entity_mentions'] = $this->sanitize_json_field($in['entity_mentions'] ?? '[]', [], 'entity_mentions', 'Topic Mentions');
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

    /** Validate a JSON field and register a settings error on failure. */
    private function sanitize_json_field($raw, $fallback, $field_key, $human_label){
        $raw = trim((string)$raw);
        if ($raw==='') return $fallback;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE){
            if (!is_array($decoded)){
                add_settings_error(self::OPT_KEY, "json_type_{$field_key}",
                    sprintf('%s must be a JSON array (e.g., [ ... ]). We received a different type.', esc_html($human_label)),
                    'error'
                );
                return $fallback;
            }
            return $decoded;
        }
        $msg = json_last_error_msg();
        $hints = [];
        if (preg_match('/syntax|unexpected/i', $msg)) {
            $hints[] = 'Check for missing commas between items.';
            $hints[] = 'Keys and strings must use straight double-quotes "like this".';
            $hints[] = 'Remove trailing commas after the last item.';
        }
        add_settings_error(self::OPT_KEY, "json_error_{$field_key}",
            sprintf('%s: Invalid JSON. %s %s', esc_html($human_label), esc_html($msg), $hints ? ('Hints: '.esc_html(implode(' ', $hints))) : ''), 'error');
        return $fallback;
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
     * Per-post metabox (overrides)
     * ----------------------*/
    public function add_metabox(){
        $post_types = get_post_types(['public'=>true],'names');
        foreach ($post_types as $pt){
            add_meta_box('yse_meta','Schema Extender Overrides',[$this,'render_metabox'],$pt,'side','default');
        }
    }

    public function render_metabox($post){
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field('yse_meta_save', 'yse_meta_nonce');

        $enabled   = get_post_meta($post->ID, '_yse_override_enabled', true) === '1';
        $pieceType = get_post_meta($post->ID, '_yse_piece_type', true);   // e.g., Service, Place, SoftwareApplication
        $pageType  = get_post_meta($post->ID, '_yse_webpage_type', true); // e.g., ContactPage, AboutPage, FAQPage, HowTo
        $mentions  = get_post_meta($post->ID, '_yse_entity_mentions', true);

        $page_types = ['','AboutPage','ContactPage','FAQPage','HowTo','ProfilePage','CollectionPage','ItemPage','WebPage'];
        echo '<div class="yse-meta">';
        echo '<p><label><input type="checkbox" name="yse_override_enabled" value="1" '.checked($enabled,true,false).'/> Enable per-page override</label></p>';

        echo '<p><label for="yse_piece_type"><strong>Schema Type (optional)</strong></label><br/>';
        echo '<input type="text" class="widefat" id="yse_piece_type" name="yse_piece_type" value="'.esc_attr($pieceType).'" placeholder="e.g., Service, Place, SoftwareApplication"/>';
        echo '<small>What this page primarily represents. Leave blank to use defaults/CPT mapping.</small></p>';

        echo '<p><label for="yse_webpage_type"><strong>WebPage @type (optional)</strong></label><br/>';
        echo '<select id="yse_webpage_type" name="yse_webpage_type" class="widefat">';
        foreach($page_types as $pt){
            printf('<option value="%s" %s>%s</option>', esc_attr($pt), selected($pageType,$pt,false), $pt ? esc_html($pt) : '(Default)');
        }
        echo '</select><small>Overrides automatic detection (About/Contact/FAQ/HowTo).</small></p>';

        echo '<p><label for="yse_entity_mentions"><strong>about/mentions JSON (optional)</strong></label><br/>';
        echo '<textarea id="yse_entity_mentions" name="yse_entity_mentions" class="widefat yse-mono" rows="6">'.esc_textarea($mentions).'</textarea>';
        echo '<small>ELI5: Authoritative topic links (Wikipedia/Wikidata). Format: JSON array of {\"@id\":\"...\"}.</small></p>';
        echo '</div>';
    }

    public function save_metabox($post_id, $post){
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['yse_meta_nonce']) || !wp_verify_nonce($_POST['yse_meta_nonce'], 'yse_meta_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $enabled = isset($_POST['yse_override_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_yse_override_enabled', $enabled);

        $pieceType = isset($_POST['yse_piece_type']) ? preg_replace('/[^A-Za-z]/','', sanitize_text_field($_POST['yse_piece_type'])) : '';
        if ($pieceType) update_post_meta($post_id, '_yse_piece_type', $pieceType); else delete_post_meta($post_id, '_yse_piece_type');

        $pageType = isset($_POST['yse_webpage_type']) ? preg_replace('/[^A-Za-z]/','', sanitize_text_field($_POST['yse_webpage_type'])) : '';
        if ($pageType) update_post_meta($post_id, '_yse_webpage_type', $pageType); else delete_post_meta($post_id, '_yse_webpage_type');

        // JSON mentions (validate; store raw JSON string for transparency)
        $mentions_raw = isset($_POST['yse_entity_mentions']) ? trim((string)wp_unslash($_POST['yse_entity_mentions'])) : '';
        if ($mentions_raw !== ''){
            $decoded = json_decode($mentions_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)){
                update_post_meta($post_id, '_yse_entity_mentions', wp_json_encode($decoded, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
            } else {
                // Keep user input but flag admin notice once (per save request)
                update_post_meta($post_id, '_yse_entity_mentions', '');
                add_filter('redirect_post_location', function($location){
                    return add_query_arg(['yse_json_error'=>'mentions'], $location);
                });
            }
        } else {
            delete_post_meta($post_id, '_yse_entity_mentions');
        }
    }

    /* ------------------------
     * Schema Filters (merge-first, per-post override support)
     * ----------------------*/
    private function hook_schema_filters(){
        // Show one-time JSON error after save_post redirect (metabox)
        add_action('admin_notices', function(){
            if (isset($_GET['yse_json_error']) && $_GET['yse_json_error']==='mentions'){
                echo '<div class="notice notice-error"><p><strong>Schema Extender:</strong> Invalid JSON in per-page <em>about/mentions</em>. Please fix the JSON (array of { "@id": "https://..." }).</p></div>';
            }
        });

        // Organization / LocalBusiness
        add_filter('wpseo_schema_organization', function($data){
            $s = get_option(self::OPT_KEY, []);
            $yoast_local_active = $this->yoast_local_available();
            $override = !empty($s['override_org']) && $s['override_org'] === '1';

            $is_local = (!$yoast_local_active) && !empty($s['is_local']) && $s['is_local'] === '1';

            if ($override) {
                $data['@type'] = $is_local ? (!empty($s['lb_subtype']) ? $s['lb_subtype'] : 'LocalBusiness') : 'Organization';
            }
            if (empty($data['@id'])) {
                $data['@id'] = $is_local ? home_url('#/schema/localbusiness') : home_url('#/schema/organization');
            }

            if ($override || empty($data['name'])) $data['name'] = !empty($s['org_name']) ? $s['org_name'] : ($data['name'] ?? get_bloginfo('name'));
            if ($override || empty($data['url']))  $data['url']  = !empty($s['org_url'])  ? $s['org_url']  : ($data['url']  ?? home_url('/'));
            if (!empty($s['org_logo']) && ($override || empty($data['logo']))) $data['logo'] = ['@type'=>'ImageObject','url'=>$s['org_logo']];

            $existing_sameas = isset($data['sameAs']) && is_array($data['sameAs']) ? $data['sameAs'] : [];
            $ours_sameas     = !empty($s['same_as']) ? (array)$s['same_as'] : [];
            $data['sameAs']  = array_values(array_unique(array_filter(array_merge($existing_sameas, $ours_sameas))));

            if (!empty($s['identifier']) && is_array($s['identifier'])) {
                $data['identifier'] = array_values(array_merge($data['identifier'] ?? [], $s['identifier']));
            }

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

        // WebPage type tweaks + mentions (respects per-post override)
        add_filter('wpseo_schema_webpage', function($data){
            if (!is_singular()) return $data;
            $s = get_option(self::OPT_KEY, []);
            global $post;
            $slug = $post ? $post->post_name : '';
            $content = $post ? get_post_field('post_content', $post) : '';

            // Per-post override?
            $enabled = get_post_meta($post->ID, '_yse_override_enabled', true) === '1';
            $pageTypeOverride = $enabled ? get_post_meta($post->ID, '_yse_webpage_type', true) : '';

            if ($enabled && $pageTypeOverride){
                $data['@type'] = $pageTypeOverride;
            } else {
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
            }

            // about/mentions: global + per-post
            $global_mentions = (!empty($s['entity_mentions']) && is_array($s['entity_mentions'])) ? $s['entity_mentions'] : [];
            $post_mentions_raw = get_post_meta($post->ID, '_yse_entity_mentions', true);
            $post_mentions = [];
            if ($post_mentions_raw){
                $dec = json_decode($post_mentions_raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)){
                    $post_mentions = $dec;
                }
            }
            $merged = array_merge($data['about'] ?? [], $global_mentions, $post_mentions);
            if (!empty($merged)){
                $data['about']    = $merged;
                $data['mentions'] = $merged;
            }

            return $data;
        }, 20);

        // Graph additions (CPT → Schema, Breadcrumb, FAQ, Video) with per-post piece override
        add_filter('wpseo_schema_graph_pieces', function($pieces, $context){
            if (!is_singular()) return $pieces;
            $s = get_option(self::OPT_KEY, []);
            global $post;

            $enabled = get_post_meta($post->ID, '_yse_override_enabled', true) === '1';
            $pieceOverride = $enabled ? get_post_meta($post->ID, '_yse_piece_type', true) : '';

            // Determine target piece type (override > map)
            $map = is_array($s['cpt_map'] ?? null) ? $s['cpt_map'] : [];
            $ptype = $post ? get_post_type($post) : '';
            $type = '';
            if ($pieceOverride){
                $type = preg_replace('/[^A-Za-z]/','', $pieceOverride);
            } elseif ($ptype && isset($map[$ptype])){
                $type = preg_replace('/[^A-Za-z]/','', $map[$ptype]);
            }

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
