<?php
/**
 * Plugin Name: Color Palette Display
 * Plugin URI: https://github.com/abhijitb/color-palette-plugin
 * Description: A simple WordPress plugin to display color palettes from hex codes in horizontal strips.
 * Version: 1.0.0
 * Author: Abhijit Bhatnagar
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ColorPalettePlugin {
    
    private $slug = 'color-palette-plugin';

    public function __construct() {
        \add_action('init', array($this, 'init'));
    }
    
    public function init() {

        // Enqueue styles
        \add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));

        // Add admin menu
        \add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        \add_action('admin_init', array($this, 'register_settings'));
        
        // Register shortcode
        \add_shortcode('color_palette', array($this, 'display_color_palette'));
        
    }
    
    public function add_admin_menu() {
        \add_options_page(
            'Color Palette Settings',
            'Color Palette',
            'manage_options',
            'color-palette-settings',
            array($this, 'admin_page')
        );
    }
    
    public function register_settings() {
        \register_setting('color_palette_settings', 'color_palette_data');
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $json_data = \sanitize_textarea_field($_POST['json_input']);
            $json_data = str_replace('\\', '', $json_data);
            $palette_data = array();
            $success = false;
            
            if (!empty($json_data)) {
                $decoded = json_decode($json_data, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $palette_data = $this->process_palette_data($decoded);
                    if (!empty($palette_data)) {
                        $success = true;
                    } else {
                        \add_settings_error('color_palette_settings', 'no_valid_palettes', 'No valid color palettes found in JSON data.');
                    }
                } else {
                    \add_settings_error('color_palette_settings', 'invalid_json', 'Invalid JSON format. Please check your syntax.');
                }
            } else {
                \add_settings_error('color_palette_settings', 'empty_json', 'JSON input cannot be empty.');
            }
            
            if ($success) {
                \update_option('color_palette_data', $palette_data);
                \add_settings_error('color_palette_settings', 'settings_updated', 'Color palettes saved successfully!', 'updated');
            }
        }
        
        $saved_palettes = \get_option('color_palette_data', array());
        
        ?>
        <div class="wrap">
            <h1>Color Palette Settings</h1>
            
            <?php \settings_errors('color_palette_settings'); ?>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Color Palettes (JSON)</th>
                        <td>
                            <textarea name="json_input" rows="15" cols="80" style="font-family: monospace;"><?php 
                                if (!empty($saved_palettes)) {
                                    echo esc_textarea(json_encode($saved_palettes, JSON_PRETTY_PRINT));
                                }
                            ?></textarea>
                            <p class="description">
                                Enter your color palettes in JSON format. Each palette should have a name (key) and color definitions with hex values.
                                <br><strong>Example:</strong> { "palette_name": {"base": "#ffffff", "accent_1": "#000000"} }
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php \submit_button(); ?>
            </form>
            
            <?php if (!empty($saved_palettes)): ?>
                <div style="margin-top: 30px;">
                    <h3>Preview</h3>
                    <?php foreach ($saved_palettes as $palette_name => $colors): ?>
                        <div style="margin-bottom: 30px;">
                            <h4><?php echo esc_html(ucfirst($palette_name)); ?> Palette</h4>
                            <div style="border: 1px solid #ddd; padding: 20px; background: #fff;">
                                <?php echo $this->render_palette($colors, $palette_name); ?>
                            </div>
                            <p><strong>Shortcode:</strong> <code>[color_palette name="<?php echo esc_attr($palette_name); ?>"]</code></p>
                        </div>
                    <?php endforeach; ?>
                    <p><strong>Show all palettes:</strong> <code>[color_palette]</code></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function process_palette_data($decoded_data) {
        $processed_palettes = array();
        
        foreach ($decoded_data as $palette_name => $palette_colors) {
            if (is_array($palette_colors)) {
                $valid_colors = array();
                
                foreach ($palette_colors as $color_name => $hex_code) {
                    if (is_string($hex_code)) {
                        $validated_hex = $this->validate_single_hex_code($hex_code);
                        if ($validated_hex) {
                            $valid_colors[$color_name] = $validated_hex;
                        }
                    }
                }
                
                if (!empty($valid_colors)) {
                    $processed_palettes[\sanitize_key($palette_name)] = $valid_colors;
                }
            }
        }
        
        return $processed_palettes;
    }
    
    private function validate_single_hex_code($code) {
        $code = trim($code);
        
        // Add # if missing
        if (!str_starts_with($code, '#')) {
            $code = '#' . $code;
        }
        
        // Validate hex code
        if (preg_match('/^#[a-fA-F0-9]{6}$/', $code)) {
            return strtoupper($code);
        }
        
        return false;
    }
    
    public function display_color_palette($atts) {
        $atts = \shortcode_atts(array(
            'name' => ''
        ), $atts);
        
        $palettes = \get_option('color_palette_data', array());
        
        if (empty($palettes)) {
            return '<p>No color palettes configured. Please set up your palettes in the admin settings.</p>';
        }
        
        // If specific palette requested
        if (!empty($atts['name']) && isset($palettes[$atts['name']])) {
            return $this->render_palette($palettes[$atts['name']], $atts['name']);
        }
        
        // If specific palette requested but not found
        if (!empty($atts['name']) && !isset($palettes[$atts['name']])) {
            return '<p>Palette "' . esc_html($atts['name']) . '" not found.</p>';
        }
        
        // Show all palettes
        $output = '';
        foreach ($palettes as $palette_name => $colors) {
            $output .= '<div class="palette-container">';
            $output .= '<h4 class="palette-title">' . esc_html(ucfirst($palette_name)) . '</h4>';
            $output .= $this->render_palette($colors, $palette_name);
            $output .= '</div>';
        }
        
        return $output;
    }
    
    private function render_palette($colors, $palette_name = '') {
        $output = '<div class="color-palette-container">';
        
        foreach ($colors as $color_name => $hex_code) {
            $output .= sprintf(
                '<div class="color-swatch" style="background-color: %s;" title="%s: %s">
                    <span class="color-code">%s</span>
                    <span class="color-name">%s</span>
                </div>',
                esc_attr($hex_code),
                esc_attr($color_name),
                esc_attr($hex_code),
                esc_html($hex_code),
                esc_html($color_name)
            );
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    public function enqueue_styles() {
        \wp_register_style(
            $this->slug,
            \plugin_dir_url(__FILE__) . 'assets/css/color-palette.css',
            array(),
            '1.0.0'
        );
        \wp_enqueue_style($this->slug);
    }
}

// Initialize the plugin
new ColorPalettePlugin();
