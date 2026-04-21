<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   SETTINGS REGISTRATION
   ============================================================ */
add_action('admin_init', function () {
    register_setting('dnap_settings', 'dnap_api_key');
    register_setting('dnap_settings', 'dnap_anthropic_key');
});

/* ============================================================
   TAG IN EVIDENZA — admin_init handlers
   Pattern Post/Redirect/Get identico agli altri handler.
   ============================================================ */
add_action('admin_init', 'dnap_vip_tags_admin_init');
function dnap_vip_tags_admin_init() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'dnap-dashboard') return;
    if (!current_user_can('manage_options')) return;

    // Aggiungi tag
    if (isset($_POST['dnap_add_tag']) && check_admin_referer('dnap_add_tag')) {
        $tag = mb_strtolower(sanitize_text_field(trim($_POST['vip_tag'] ?? '')));
        if ($tag !== '') {
            $tags = get_option('dnap_vip_tags', ['zannini', 'giovanni zannini']);
            if (!in_array($tag, $tags, true)) {
                $tags[] = $tag;
                update_option('dnap_vip_tags', array_values($tags));
            }
        }
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=tag_added'));
        exit;
    }

    // Rimuovi tag
    if (isset($_POST['dnap_remove_tag']) && check_admin_referer('dnap_remove_tag')) {
        $tag  = mb_strtolower(sanitize_text_field(trim($_POST['vip_tag_remove'] ?? '')));
        $tags = get_option('dnap_vip_tags', ['zannini', 'giovanni zannini']);
        $tags = array_values(array_filter($tags, fn($t) => $t !== $tag));
        update_option('dnap_vip_tags', $tags);
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=tag_removed'));
        exit;
    }
}

/* ============================================================
   TAG IN EVIDENZA — sezione UI del pannello
   ============================================================ */
function dnap_vip_tags_section(): void {
    // Pre-popola con valori di default al primo accesso
    if (get_option('dnap_vip_tags') === false) {
        update_option('dnap_vip_tags', ['zannini', 'giovanni zannini']);
    }
    $tags = get_option('dnap_vip_tags', []);
    ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;">
        <h2 style="margin-top:0;">⭐ Tag in Evidenza</h2>
        <p style="color:#666;font-size:13px;margin:0 0 14px;">
            I post che contengono questi tag nel titolo o nel contenuto vengono
            marcati automaticamente come "in evidenza" (sticky).
            I tag sono case-insensitive.
        </p>

        <!-- Lista tag attivi -->
        <div style="border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-bottom:12px;max-width:480px;">
            <?php if (empty($tags)) : ?>
                <div style="padding:12px 16px;color:#888;font-size:13px;">
                    Nessun tag configurato.
                </div>
            <?php else : ?>
                <?php foreach ($tags as $tag) : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;
                            padding:9px 14px;border-bottom:1px solid #eee;background:#fafafa;">
                    <span style="font-size:13px;font-family:monospace;color:#1d2327;">
                        <?php echo esc_html($tag); ?>
                    </span>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('dnap_remove_tag'); ?>
                        <input type="hidden" name="vip_tag_remove" value="<?php echo esc_attr($tag); ?>">
                        <button type="submit" name="dnap_remove_tag" value="1"
                                style="background:none;border:1px solid #c00;color:#c00;
                                       border-radius:4px;padding:3px 10px;font-size:12px;
                                       cursor:pointer;line-height:1.4;">
                            Rimuovi
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Aggiungi tag -->
        <form method="post" style="display:flex;gap:8px;max-width:480px;">
            <?php wp_nonce_field('dnap_add_tag'); ?>
            <input type="text" name="vip_tag" placeholder="Nuovo tag (es. festa del mare)…"
                   required autocomplete="off"
                   style="flex:1;padding:6px 10px;border:1px solid #ddd;
                          border-radius:4px;font-size:13px;">
            <button type="submit" name="dnap_add_tag" value="1"
                    class="button button-primary">
                Aggiungi
            </button>
        </form>
    </div>
    <?php
}
