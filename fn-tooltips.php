<?php
/**
 * Plugin Name: Fairfield Tooltips (MU)
 * Description: Lightweight, centrally-managed tooltips (hover on desktop, tap on mobile). Provides [fn_tooltip] shortcode + Settings admin registry.
 * Author: Fairfield Nutrition
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

final class FN_Tooltips_MU {
    const OPTION_KEY = 'fn_tooltips_registry';
    const NONCE_KEY  = 'fn_tooltips_admin_nonce';
    const SLUG       = 'fn-tooltips';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_post']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_shortcode('fn_tooltip', [__CLASS__, 'shortcode_tooltip']);
    }

    /* ---------------------------
     * Admin
     * --------------------------- */

    public static function admin_menu(): void {
        add_options_page(
            'Tooltips',
            'Tooltips',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function get_registry(): array {
        $reg = get_option(self::OPTION_KEY, []);
        return is_array($reg) ? $reg : [];
    }

    public static function save_registry(array $reg): void {
        // Normalise keys and ensure structure
        $clean = [];
        foreach ($reg as $key => $row) {
            $k = self::normalise_key((string)$key);
            if ($k === '') continue;

            $title   = isset($row['title']) ? sanitize_text_field((string)$row['title']) : '';
            $content = isset($row['content']) ? (string)$row['content'] : '';

            // Allow limited HTML; you can tighten this if you want.
            $content = wp_kses_post($content);

            $clean[$k] = [
                'title'   => $title,
                'content' => $content,
            ];
        }
        update_option(self::OPTION_KEY, $clean, false);
    }

    private static function normalise_key(string $key): string {
        $key = trim($key);
        $key = strtolower($key);
        // allow a-z 0-9 _ -
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key);
        $key = trim($key, '_-');
        return $key;
    }

    public static function handle_admin_post(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;

        // Only handle posts to our page
        if (empty($_POST['fn_tooltips_action'])) return;

        check_admin_referer(self::NONCE_KEY);

        $action = sanitize_text_field((string)$_POST['fn_tooltips_action']);
        $reg = self::get_registry();

        if ($action === 'save') {
            $key_raw = isset($_POST['tooltip_key']) ? (string)$_POST['tooltip_key'] : '';
            $key = self::normalise_key($key_raw);

            $title = isset($_POST['tooltip_title']) ? sanitize_text_field((string)$_POST['tooltip_title']) : '';
            $content = isset($_POST['tooltip_content']) ? (string)$_POST['tooltip_content'] : '';
            $content = wp_kses_post($content);

            if ($key !== '') {
                $reg[$key] = [
                    'title'   => $title,
                    'content' => $content,
                ];
                self::save_registry($reg);
            }

            wp_safe_redirect(self::admin_url(['updated' => 1, 'edit' => $key]));
            exit;
        }

        if ($action === 'delete') {
            $key_raw = isset($_POST['tooltip_key']) ? (string)$_POST['tooltip_key'] : '';
            $key = self::normalise_key($key_raw);

            if ($key !== '' && isset($reg[$key])) {
                unset($reg[$key]);
                self::save_registry($reg);
            }

            wp_safe_redirect(self::admin_url(['deleted' => 1]));
            exit;
        }
    }

    private static function admin_url(array $args = []): string {
        $base = admin_url('options-general.php?page=' . self::SLUG);
        if (!$args) return $base;
        return add_query_arg($args, $base);
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) return;

        $reg = self::get_registry();
        ksort($reg);

        $edit_key = isset($_GET['edit']) ? self::normalise_key((string)$_GET['edit']) : '';
        $is_edit = $edit_key !== '' && isset($reg[$edit_key]);

        $form_key = $is_edit ? $edit_key : '';
        $form_title = $is_edit ? ($reg[$edit_key]['title'] ?? '') : '';
        $form_content = $is_edit ? ($reg[$edit_key]['content'] ?? '') : '';

        ?>
        <div class="wrap">
            <h1>Tooltips</h1>

            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Tooltip saved.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible"><p>Tooltip deleted.</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex: 1 1 520px; min-width: 320px;">
                    <h2><?php echo $is_edit ? 'Edit Tooltip' : 'Add Tooltip'; ?></h2>

                    <form method="post" action="">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="fn_tooltips_action" value="save" />

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="tooltip_key">Key (slug)</label></th>
                                <td>
                                    <input
                                        name="tooltip_key"
                                        id="tooltip_key"
                                        type="text"
                                        class="regular-text"
                                        value="<?php echo esc_attr($form_key); ?>"
                                        <?php echo $is_edit ? 'readonly' : ''; ?>
                                        placeholder="e.g. tier_foundational"
                                        required
                                    />
                                    <p class="description">Use this key in shortcodes, e.g. <code>[fn_tooltip key="tier_foundational"]</code></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tooltip_title">Title (optional)</label></th>
                                <td>
                                    <input
                                        name="tooltip_title"
                                        id="tooltip_title"
                                        type="text"
                                        class="regular-text"
                                        value="<?php echo esc_attr($form_title); ?>"
                                        placeholder="Optional heading shown in tooltip"
                                    />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tooltip_content">Content</label></th>
                                <td>
                                    <textarea
                                        name="tooltip_content"
                                        id="tooltip_content"
                                        rows="10"
                                        class="large-text code"
                                        placeholder="Short, skimmable text. Limited HTML allowed."
                                        required
                                    ><?php echo esc_textarea($form_content); ?></textarea>
                                    <p class="description">Keep this short. This is a tooltip, not a page.</p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button($is_edit ? 'Update Tooltip' : 'Add Tooltip'); ?>

                        <?php if ($is_edit): ?>
                            <p><a href="<?php echo esc_url(self::admin_url()); ?>">Add a new tooltip</a></p>
                        <?php endif; ?>
                    </form>
                </div>

                <div style="flex: 1 1 520px; min-width: 320px;">
                    <h2>Existing Tooltips</h2>

                    <?php if (empty($reg)): ?>
                        <p>No tooltips yet. Add your first tooltip on the left.</p>
                    <?php else: ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width: 220px;">Key</th>
                                    <th>Title</th>
                                    <th style="width: 220px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reg as $key => $row): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($key); ?></code></td>
                                        <td><?php echo esc_html($row['title'] ?? ''); ?></td>
                                        <td>
                                            <a class="button button-small" href="<?php echo esc_url(self::admin_url(['edit' => $key])); ?>">Edit</a>

                                            <form method="post" action="" style="display:inline;">
                                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                                <input type="hidden" name="fn_tooltips_action" value="delete" />
                                                <input type="hidden" name="tooltip_key" value="<?php echo esc_attr($key); ?>" />
                                                <button type="submit" class="button button-small button-link-delete"
                                                        onclick="return confirm('Delete tooltip <?php echo esc_js($key); ?>?');">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <h3 style="margin-top:18px;">Usage</h3>
                        <p>Icon trigger: <code>[fn_tooltip key="your_key"]</code></p>
                        <p>Optional attributes: <code>[fn_tooltip key="your_key" label="Custom aria label" class="custom-class"]</code></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------
     * Frontend assets + shortcodes
     * --------------------------- */

    public static function enqueue_assets(): void {
        // Only enqueue on frontend
        if (is_admin()) return;

        $ver = '1.0.0';

        // Inline assets (simple MU-plugin deployment)
        $css = self::frontend_css();
        $js  = self::frontend_js();

        wp_register_style('fn-tooltips', false, [], $ver);
        wp_enqueue_style('fn-tooltips');
        wp_add_inline_style('fn-tooltips', $css);

        wp_register_script('fn-tooltips', '', [], $ver, true);
        wp_enqueue_script('fn-tooltips');

        // Pass tooltip registry to JS
        $reg = self::get_registry();
        wp_add_inline_script('fn-tooltips', 'window.FN_TOOLTIPS = ' . wp_json_encode($reg) . ';', 'before');
        wp_add_inline_script('fn-tooltips', $js, 'after');
    }

    public static function shortcode_tooltip($atts): string {
        $atts = shortcode_atts([
            'key'   => '',
            'class' => '',
            'label' => '', // optional aria-label override
        ], (array)$atts, 'fn_tooltip');

        $key = self::normalise_key((string)$atts['key']);
        if ($key === '') return '';

        $extra_class = sanitize_html_class((string)$atts['class']);
        $aria_label  = trim((string)$atts['label']);

        if ($aria_label === '') {
            $aria_label = 'More information';
        }

        return self::render_icon_button($key, $extra_class, $aria_label);
    }

    private static function render_icon_button(string $key, string $extra_class, string $aria_label): string {
        // Using span instead of button to avoid WordPress content filtering
        return sprintf(
            '<span class="fn-tooltip-trigger %s" data-fn-tooltip-key="%s" role="button" tabindex="0" aria-label="%s" aria-expanded="false">' .
            '<span class="fn-tooltip-icon" aria-hidden="true">i</span>' .
            '</span>',
            esc_attr($extra_class),
            esc_attr($key),
            esc_attr($aria_label)
        );
    }

    private static function frontend_css(): string {
        return <<<CSS
/* UPDATED VERSION v1.1 - If you see this in browser inspector, file is loaded */
/* Trigger button (tiny icon, larger tap target) */
.fn-tooltip-trigger{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-left:2px;
  padding:4px;           /* tap target */
  border:0;
  background:transparent;
  cursor:pointer;
  line-height:1;
  vertical-align:middle;
}
.fn-tooltip-trigger:focus{
  outline:2px solid currentColor;
  outline-offset:2px;
}

/* Icon itself */
.fn-tooltip-icon{
  display:inline-block !important;
  width:24px !important;
  height:24px !important;
  border:2px solid currentColor !important;
  border-radius:50% !important;
  text-align:center !important;
  line-height:20px !important;
  font-size:14px !important;
  font-weight:700 !important;
  opacity:.85 !important;
  vertical-align:middle !important;
}

/* Tooltip popover */
#fn-tooltip-popover{
  position:fixed;
  z-index:99999;
  max-width:min(320px, calc(100vw - 24px));
  max-height:min(400px, 80vh);
  overflow-y:auto;
  padding:12px 14px;
  padding-top:10px;
  border:1px solid rgba(0,0,0,.15);
  border-radius:10px;
  background:#fff;
  box-shadow:0 10px 30px rgba(0,0,0,.12);
  font-size:14px;
  line-height:1.35;
}
#fn-tooltip-popover .fn-tooltip-header{
  display:block;
  position:relative;
  margin-bottom:3px;
  padding-right:28px;
}
#fn-tooltip-popover .fn-tooltip-title{
  font-weight:700;
  margin-bottom:0;
}
#fn-tooltip-popover .fn-tooltip-close{
  position:absolute;
  top:-8px;
  right:-8px;
  width:24px;
  height:24px;
  padding:0;
  border:0;
  background:transparent;
  cursor:pointer;
  font-size:22px;
  line-height:1;
  color:rgba(0,0,0,.5);
  border-radius:4px;
}
#fn-tooltip-popover .fn-tooltip-close:hover{
  background:rgba(0,0,0,.05);
  color:rgba(0,0,0,.8);
}
#fn-tooltip-popover .fn-tooltip-content{
  margin:0;
}
CSS;
    }

    private static function frontend_js(): string {
        return <<<JS
(function(){
  const registry = (window.FN_TOOLTIPS || {});
  const hasHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;

  let popover = null;
  let activeKey = null;
  let activeEl = null;

  function ensurePopover(){
    if (popover) return popover;
    popover = document.createElement('div');
    popover.id = 'fn-tooltip-popover';
    popover.setAttribute('role', 'tooltip');
    popover.style.display = 'none';
    document.body.appendChild(popover);
    return popover;
  }

  function getContent(key){
    const row = registry[key];
    if (!row) return null;
    const title = row.title || '';
    const content = row.content || '';
    if (!title && !content) return null;
    return { title, content };
  }

  function setExpanded(el, expanded){
    if (!el) return;
    if (el.classList && el.classList.contains('fn-tooltip-trigger')) {
      el.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
  }

  function positionPopover(el){
    const r = el.getBoundingClientRect();
    const p = ensurePopover();

    // Prefer below-right, but keep inside viewport.
    const gap = 10;
    const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);

    p.style.display = 'block';
    p.style.left = '0px';
    p.style.top = '0px';

    const pr = p.getBoundingClientRect();

    let left = r.left + gap;
    let top  = r.bottom + gap;

    if (left + pr.width > vw - 12) left = vw - pr.width - 12;
    if (left < 12) left = 12;

    if (top + pr.height > vh - 12) {
      // try above
      top = r.top - pr.height - gap;
    }
    if (top < 12) top = 12;

    p.style.left = Math.round(left) + 'px';
    p.style.top  = Math.round(top) + 'px';
  }

  function openTooltip(el, key){
    const data = getContent(key);
    if (!data) return;

    const p = ensurePopover();
    const html = [];

    // Add header with optional title and close button
    html.push('<div class="fn-tooltip-header">');
    if (data.title) {
      html.push('<div class="fn-tooltip-title">' + escapeHtml(data.title) + '</div>');
    } else {
      html.push('<div class="fn-tooltip-title"></div>');
    }
    html.push('<button type="button" class="fn-tooltip-close" aria-label="Close">×</button>');
    html.push('</div>');

    // content can contain limited HTML (from wp_kses_post), so do NOT escape it.
    html.push('<div class="fn-tooltip-content">' + data.content + '</div>');
    p.innerHTML = html.join('');

    activeKey = key;
    activeEl = el;
    setExpanded(activeEl, true);

    positionPopover(el);
    p.style.display = 'block';
  }

  function closeTooltip(){
    if (!popover) return;
    popover.style.display = 'none';
    if (activeEl) setExpanded(activeEl, false);
    activeKey = null;
    activeEl = null;
  }

  function toggleTooltip(el, key){
    if (activeKey === key && popover && popover.style.display === 'block') {
      closeTooltip();
    } else {
      openTooltip(el, key);
    }
  }

  // Helpers
  function escapeHtml(s){
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function closestTrigger(target){
    if (!target) return null;
    return target.closest('[data-fn-tooltip-key]');
  }

  // Close button click handler
  document.addEventListener('click', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('fn-tooltip-close')) {
      e.preventDefault();
      e.stopPropagation();
      closeTooltip();
    }
  });

  // Desktop hover behaviour
  if (hasHover) {
    document.addEventListener('mouseover', function(e){
      const t = closestTrigger(e.target);
      if (!t) return;
      const key = t.getAttribute('data-fn-tooltip-key');
      if (!key) return;
      openTooltip(t, key);
    });

    document.addEventListener('mouseout', function(e){
      const t = closestTrigger(e.target);
      if (!t) return;

      // If moving into the popover, don't immediately close.
      if (popover && e.relatedTarget && popover.contains(e.relatedTarget)) return;

      closeTooltip();
    });

    // If leaving popover itself, close
    document.addEventListener('mouseover', function(e){
      if (!popover) return;
      if (popover.style.display !== 'block') return;
      // keep open while hovering popover
    });

    document.addEventListener('mouseout', function(e){
      if (!popover) return;
      if (popover.style.display !== 'block') return;
      if (e.target === popover || (popover.contains(e.target) && !popover.contains(e.relatedTarget))) {
        // leaving popover to somewhere else
        closeTooltip();
      }
    });

    // Focus accessibility
    document.addEventListener('focusin', function(e){
      const t = closestTrigger(e.target);
      if (!t) return;
      const key = t.getAttribute('data-fn-tooltip-key');
      if (!key) return;
      openTooltip(t, key);
    });

    document.addEventListener('focusout', function(e){
      const t = closestTrigger(e.target);
      if (!t) return;
      // small delay to allow focus shift into popover? (popover isn't focusable by default)
      setTimeout(function(){
        if (!document.activeElement) return;
        const now = closestTrigger(document.activeElement);
        if (!now) closeTooltip();
      }, 0);
    });
  }

  // Mobile / tap behaviour (also works on desktop for click)
  document.addEventListener('click', function(e){
    const t = closestTrigger(e.target);
    if (!t) {
      // clicked outside trigger/popover
      if (popover && popover.style.display === 'block' && !(popover.contains(e.target))) closeTooltip();
      return;
    }

    const key = t.getAttribute('data-fn-tooltip-key');
    if (!key) return;

    // Prevent unintended actions if wrapped text is inside a link
    // If user clicked an actual <a>, let it behave normally.
    if (e.target && e.target.closest && e.target.closest('a')) return;

    e.preventDefault();
    e.stopPropagation();
    toggleTooltip(t, key);
  }, true);

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeTooltip();
  });

  window.addEventListener('scroll', function(){
    if (popover && popover.style.display === 'block' && activeEl) positionPopover(activeEl);
  }, { passive:true });

  window.addEventListener('resize', function(){
    if (popover && popover.style.display === 'block' && activeEl) positionPopover(activeEl);
  });
})();
JS;
    }
}

FN_Tooltips_MU::init();
