<?php
if (!defined('ABSPATH')) {
    exit;
}

$privacy_policy_url = gp_get_public_privacy_policy_url();
?>
<footer class="gp-footer">
    <div class="gp-container">
        <div class="gp-footer__cols">
            <div>
                <h3>GP GREGOR Swiss</h3>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/kontakt')); ?>">Kontakt</a></li>
                    <li><a href="<?php echo esc_url(home_url('/regulamin-platnosci')); ?>">Regulamin</a></li>
                    <li><a href="<?php echo esc_url($privacy_policy_url); ?>">Polityka prywatności</a></li>
                </ul>
            </div>
            <div>
                <h3>Kontakt</h3>
                <ul>
                    <li>tel. 504 266 984</li>
                    <li>biuro@gpswiss.pl</li>
                </ul>
            </div>
        </div>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
