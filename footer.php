<?php if (!defined('ABSPATH')) { exit; } ?>
<footer class="gp-footer">
    <div class="gp-container">
        <div class="gp-footer__cols">
            <div>
                <h3>GP Swiss</h3>
                <ul>
                    <li><a href="#">O nas</a></li>
                    <li><a href="<?php echo esc_url(home_url('/kontakt')); ?>">Kontakt</a></li>
                    <li><a href="<?php echo esc_url(home_url('/regulamin-platnosci')); ?>">Regulamin</a></li>
                    <li><a href="<?php echo esc_url(home_url('/polityka-prywatnosci')); ?>">Polityka prywatności</a></li>
                </ul>
            </div>
            <div>
                <h3>Obsługa klienta</h3>
                <ul>
                    <li><a href="#">Dostawa i płatność</a></li>
                    <li><a href="<?php echo esc_url(home_url('/zwroty')); ?>">Zwroty</a></li>
                    <li><a href="#">FAQ</a></li>
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
