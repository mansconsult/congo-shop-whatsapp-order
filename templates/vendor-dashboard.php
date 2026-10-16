<?php defined( 'ABSPATH' ) || exit; ?>
<section class="cswa-vendor">
<header class="csv-header"><div><span class="csv-eyebrow">Espace marchand</span><h1><?php echo is_user_logged_in() ? esc_html( CSWA_Vendors::shop_name( get_current_user_id() ) ) : 'Développe ta boutique'; ?></h1></div>
<?php if ( is_user_logged_in() ) : ?><a href="<?php echo esc_url( wp_logout_url( CSWA_Vendors::portal_url() ) ); ?>">Se déconnecter</a><?php endif; ?></header>
<?php if ( ! empty( $_GET['cswa_message'] ) ) : ?><p class="csv-notice" role="status"><?php echo esc_html( mb_substr( sanitize_text_field( wp_unslash( $_GET['cswa_message'] ) ), 0, 500 ) ); ?></p><?php endif; ?>
<?php if ( ! is_user_logged_in() ) : ?>
<div class="csv-columns"><div class="csv-card"><h2>J’ai déjà un compte</h2><?php wp_login_form( array( 'redirect' => CSWA_Vendors::portal_url(), 'label_username' => 'Adresse e-mail (ou ancien identifiant)', 'label_password' => 'Mot de passe', 'label_log_in' => 'Se connecter' ) ); ?><a href="<?php echo esc_url( wp_lostpassword_url( CSWA_Vendors::portal_url() ) ); ?>">Mot de passe oublié ?</a></div>
<div class="csv-card"><h2>Devenir marchand</h2><p>Inscris ta boutique. Tu pourras publier tes produits dès l’approbation de ton compte.</p>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="cswa_vendor_register"><?php wp_nonce_field( 'cswa_vendor_register' ); ?>
<label>Nom de la boutique<input name="shop" required maxlength="100" autocomplete="organization"></label>
<label>Adresse e-mail (identifiant de connexion)<input type="email" name="email" required maxlength="100" autocomplete="username"></label>
<label>Nom du propriétaire de la boutique<input name="owner_name" required minlength="2" maxlength="120" autocomplete="name"></label>
<label>Activité ou produits vendus<textarea name="activity" required minlength="2" maxlength="1000" rows="3" placeholder="Décris ton activité ou les produits que tu vends"></textarea></label>
<?php CSWA_Vendors::shop_detail_fields(); ?>
<label>Numéro WhatsApp international<input type="tel" name="phone" placeholder="243…" required autocomplete="tel"></label>
<label>Mot de passe (12 caractères minimum)<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"></label>
<div hidden aria-hidden="true"><label>Site web<input name="website" tabindex="-1" autocomplete="off"></label></div>
<button class="csv-button">Demander un compte marchand</button></form></div></div>
<?php elseif ( ! CSWA_Vendors::active( get_current_user_id() ) ) : ?>
<div class="csv-card"><h2>Accès marchand</h2><p><?php echo in_array( 'cswa_pending', wp_get_current_user()->roles, true ) ? 'Ton inscription est en attente d’approbation. Tu recevras un e-mail quand ton compte sera approuvé. Pense à vérifier tes courriers indésirables.' : 'Ce compte ne dispose pas d’un accès marchand actif. Contacte l’administrateur du site.'; ?></p>
<?php if ( current_user_can( 'manage_options' ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=cswa-vendors' ) ); ?>">Gérer les marchands</a><?php endif; ?></div>
<?php else :
$vendor = get_current_user_id();
$view = sanitize_key( $_GET['view'] ?? 'overview' );
if ( ! in_array( $view, array( 'overview', 'products', 'edit', 'orders', 'order', 'profile' ), true ) ) { $view = 'overview'; }
$base = CSWA_Vendors::portal_url();
?>
<nav class="csv-nav" aria-label="Espace marchand"><?php foreach ( array( 'overview' => 'Vue d’ensemble', 'products' => 'Mes produits', 'orders' => 'Mes commandes', 'profile' => 'Ma boutique' ) as $key => $label ) : ?><a <?php echo $view === $key ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'view', $key, $base ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
<?php if ( 'overview' === $view ) : ?>
<div class="csv-stats">
<?php foreach ( array( 'cswa-new' => 'À confirmer', 'cswa-confirmed' => 'Confirmées', 'cswa-ready' => 'Prêtes', 'cswa-delivered' => 'Livrées' ) as $status => $label ) :
$stats = wc_get_orders( array( 'cswa_vendor_id' => $vendor, 'status' => $status, 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) ); ?>
<div class="csv-card"><strong><?php echo (int) $stats->total; ?></strong><span><?php echo esc_html( $label ); ?></span></div>
<?php endforeach; ?></div>
<div class="csv-card"><h2>Ta boutique, au quotidien</h2><p>Publie tes produits, retrouve les nouvelles demandes et indique leur avancement.</p><p>Le client envoie son message depuis WhatsApp. Une demande enregistrée ici ne prouve pas que le message a été envoyé ou que le paiement a été reçu.</p><a class="csv-button" href="<?php echo esc_url( add_query_arg( 'view', 'edit', $base ) ); ?>">Ajouter un produit</a> <a href="<?php echo esc_url( add_query_arg( 'view', 'orders', $base ) ); ?>">Voir les commandes</a></div>
<?php elseif ( 'products' === $view ) :
$page = max( 1, absint( $_GET['vp'] ?? 1 ) );
$products = new WP_Query( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => 20, 'paged' => $page, 'meta_key' => '_cswa_vendor_id', 'meta_value' => $vendor ) ); ?>
<div class="csv-toolbar"><h2>Mes produits</h2><a class="csv-button" href="<?php echo esc_url( add_query_arg( 'view', 'edit', $base ) ); ?>">Ajouter un produit</a></div>
<div class="csv-table-wrap"><table><thead><tr><th>Produit</th><th>Prix</th><th>Publication</th><th>Disponibilité</th><th>Lien produit</th><th></th></tr></thead><tbody>
<?php foreach ( $products->posts as $post ) : $product = wc_get_product( $post->ID ); if ( ! $product ) { continue; } ?>
<tr><td><?php echo esc_html( $product->get_name() ); ?></td><td><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></td><td><?php echo 'publish' === $product->get_status() ? 'Publié' : 'Brouillon / en attente'; ?></td><td><?php echo $product->is_in_stock() ? 'Disponible' : 'Indisponible'; ?></td><td><?php if ( 'publish' === $product->get_status() ) : ?><a href="<?php echo esc_url( $product->get_permalink() ); ?>" target="_blank" rel="noopener noreferrer">Voir le produit</a><?php else : ?><span>Disponible après publication</span><?php endif; ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'view' => 'edit', 'product_id' => $product->get_id() ), $base ) ); ?>">Modifier</a></td></tr>
<?php endforeach; if ( ! $products->posts ) : ?><tr><td colspan="6">Tu n’as pas encore de produit.</td></tr><?php endif; ?></tbody></table></div>
<?php echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( array( 'view' => 'products', 'vp' => '%#%' ), $base ), 'total' => $products->max_num_pages, 'current' => $page ) ) ); ?>
<?php elseif ( 'edit' === $view ) :
$id = absint( $_GET['product_id'] ?? 0 );
if ( $id && ! CSWA_Vendors::owns_product( $id, $vendor ) ) : ?><p>Produit inaccessible.</p>
<?php else : $product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
if ( ! $product || ! $product->is_type( 'simple' ) ) : ?><p>La modification des produits à variantes est réservée à l’administrateur.</p>
<?php else : ?>
<div class="csv-card"><h2><?php echo $id ? 'Modifier mon produit' : 'Nouveau produit'; ?></h2>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="cswa_vendor_save"><input type="hidden" name="kind" value="product"><input type="hidden" name="product_id" value="<?php echo (int) $id; ?>"><?php wp_nonce_field( 'cswa_vendor_save' ); ?>
<label>Nom du produit<input name="name" required maxlength="200" value="<?php echo esc_attr( $product->get_name() ); ?>"></label>
<label>Prix (<?php echo esc_html( get_woocommerce_currency() ); ?>)<input name="price" type="number" min="0" step="0.01" required value="<?php echo esc_attr( $product->get_regular_price() ); ?>"></label>
<label>Description courte<textarea name="short_description" rows="3"><?php echo esc_textarea( $product->get_short_description() ); ?></textarea></label>
<label>Description<textarea name="description" rows="7"><?php echo esc_textarea( $product->get_description() ); ?></textarea></label>
<label>Catégorie<select name="category"><option value="0">Sans catégorie</option><?php foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $term ) : ?><option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( $term->term_id, $product->get_category_ids(), true ) ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
<label>Photos (JPEG, PNG ou WebP, 5 maximum, 5 Mo chacune)<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>
<?php if ( $product->get_image_id() ) : ?><div class="csv-images"><?php foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $image_id ) { echo wp_get_attachment_image( $image_id, 'thumbnail' ); } ?></div><label class="csv-check"><input type="checkbox" name="remove_images" value="1"> Retirer les photos actuelles</label><?php endif; ?>
<label class="csv-check"><input type="checkbox" name="available" value="1" <?php checked( ! $id || $product->is_in_stock() ); ?>> Produit disponible</label>
<label>Publication<select name="status"><option value="draft" <?php selected( $product->get_status(), 'draft' ); ?>>Brouillon</option><option value="publish" <?php selected( $product->get_status(), 'publish' ); ?>>Publié</option></select></label>
<button class="csv-button">Enregistrer le produit</button></form></div>
<?php endif; endif; ?>
<?php elseif ( 'orders' === $view ) :
$page = max( 1, absint( $_GET['vp'] ?? 1 ) );
$filter = sanitize_key( $_GET['status'] ?? '' );
$args = array( 'cswa_vendor_id' => $vendor, 'limit' => 20, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC' );
if ( isset( CSWA_Orders::labels()[ $filter ] ) ) { $args['status'] = $filter; }
$orders = wc_get_orders( $args ); ?>
<h2>Mes commandes</h2><form method="get" action="<?php echo esc_url( $base ); ?>" class="csv-toolbar"><input type="hidden" name="page_id" value="<?php echo (int) get_queried_object_id(); ?>"><input type="hidden" name="view" value="orders"><label>Statut<select name="status"><option value="">Tous</option><?php foreach ( CSWA_Orders::labels() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button class="csv-button">Filtrer</button></form>
<div class="csv-table-wrap"><table><thead><tr><th>Commande</th><th>Date</th><th>Client</th><th>Montant des articles</th><th>Statut</th></tr></thead><tbody>
<?php foreach ( $orders->orders as $order ) : if ( ! CSWA_Vendors::owns_order( $order, $vendor ) ) { continue; } ?>
<tr><td><a href="<?php echo esc_url( add_query_arg( array( 'view' => 'order', 'order_id' => $order->get_id() ), $base ) ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><?php if ( 'yes' === $order->get_meta( '_cswa_test_mode' ) ) { echo ' (test)'; } ?></td><td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td><td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td><td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td><td>
<?php $next = CSWA_Vendors::transitions( $order->get_status() ); ?>
<form method="post" class="csv-order-status" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="cswa_vendor_save"><input type="hidden" name="kind" value="order"><input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'cswa_vendor_save' ) ); ?>">
<select name="status" aria-label="<?php echo esc_attr( 'Statut de la commande ' . $order->get_order_number() ); ?>" <?php disabled( empty( $next ) ); ?>>
<option value="<?php echo esc_attr( $order->get_status() ); ?>" selected><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></option>
<?php foreach ( $next as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( CSWA_Orders::labels()[ $status ] ); ?></option><?php endforeach; ?>
</select>
<?php if ( $next ) : ?><button class="csv-button" type="submit">Enregistrer</button><?php endif; ?>
</form></td></tr>
<?php endforeach; if ( ! $orders->total ) : ?><tr><td colspan="5">Aucune commande pour le moment.</td></tr><?php endif; ?></tbody></table></div>
<?php echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( array( 'view' => 'orders', 'status' => $filter, 'vp' => '%#%' ), $base ), 'total' => $orders->max_num_pages, 'current' => $page ) ) ); ?>
<?php elseif ( 'order' === $view ) :
$order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
if ( ! CSWA_Vendors::owns_order( $order, $vendor ) ) : ?><p>Commande inaccessible.</p>
<?php else : $payload = (array) $order->get_meta( '_cswa_payload' ); $customer = $payload['customer'] ?? array(); ?>
<div class="csv-card"><h2>Commande #<?php echo esc_html( $order->get_order_number() ); ?></h2><p><?php echo esc_html( $order->get_meta( '_cswa_ref' ) ); ?> · <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></p>
<dl><dt>Client</dt><dd><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></dd><dt>Téléphone</dt><dd><?php echo esc_html( $order->get_billing_phone() ); ?></dd><dt>E-mail</dt><dd><?php echo esc_html( $order->get_billing_email() ); ?></dd><dt>Mode et adresse</dt><dd><?php echo esc_html( implode( ', ', array_filter( array( $customer['mode_label'] ?? '', $customer['address'] ?? '', $customer['commune'] ?? '', $customer['city'] ?? '', $customer['country'] ?? '' ) ) ) ); ?></dd><dt>Précisions</dt><dd><?php echo nl2br( esc_html( $order->get_customer_note() ) ); ?></dd></dl>
<ul><?php foreach ( $order->get_items() as $item ) : ?><li><?php echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ); ?></li><?php endforeach; ?></ul>
<p>Montant des articles : <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>. Livraison à confirmer.</p>
<?php $phone = CSWA_Number::normalize( $order->get_billing_phone() ); if ( $phone ) : ?><a class="csv-button" href="<?php echo esc_url( 'https://wa.me/' . $phone . '?text=' . rawurlencode( 'Bonjour, je vous contacte au sujet de votre demande ' . $order->get_meta( '_cswa_ref' ) . '.' ) ); ?>" target="_blank" rel="noopener">Contacter le client sur WhatsApp</a><?php endif; ?>
<?php $next = CSWA_Vendors::transitions( $order->get_status() ); if ( $next ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cswa_vendor_save"><input type="hidden" name="kind" value="order"><input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>"><?php wp_nonce_field( 'cswa_vendor_save' ); ?>
<label>Avancement<select name="status"><?php foreach ( $next as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( CSWA_Orders::labels()[ $status ] ); ?></option><?php endforeach; ?></select></label><button class="csv-button">Mettre à jour</button></form>
<?php endif; ?></div><?php endif; ?>
<?php elseif ( 'profile' === $view ) : ?>
<div class="csv-card"><h2>Ma boutique</h2><form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cswa_vendor_save"><input type="hidden" name="kind" value="profile"><?php wp_nonce_field( 'cswa_vendor_save' ); ?>
<label>Nom de la boutique<input name="shop" required maxlength="100" value="<?php echo esc_attr( CSWA_Vendors::shop_name( $vendor ) ); ?>"></label>
<label>Nom du propriétaire de la boutique<input name="owner_name" maxlength="120" autocomplete="name" value="<?php echo esc_attr( get_user_meta( $vendor, '_cswa_owner_name', true ) ); ?>"></label>
<label>Activité ou produits vendus<textarea name="activity" maxlength="1000" rows="3"><?php echo esc_textarea( get_user_meta( $vendor, '_cswa_activity', true ) ); ?></textarea></label>
<?php CSWA_Vendors::shop_detail_fields( $vendor ); ?>
<label>Numéro WhatsApp international<input type="tel" name="phone" required value="<?php echo esc_attr( get_user_meta( $vendor, '_cswa_whatsapp', true ) ); ?>"></label><p>Ce numéro recevra les nouvelles demandes pour tes produits.</p><button class="csv-button">Enregistrer</button></form></div>
<?php endif; endif; ?>
</section>
