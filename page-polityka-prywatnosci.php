<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php while (have_posts()) : the_post(); ?>
            <article <?php post_class('gp-privacy-policy'); ?>>
                <h1><?php the_title(); ?></h1>

                <?php if (trim((string) get_the_content()) !== '') : ?>
                    <div class="gp-privacy-policy__content">
                        <?php the_content(); ?>
                    </div>
                <?php else : ?>
                    <div class="gp-privacy-policy__content">
                        <h2>1. Informacje ogólne</h2>
                        <p>Niniejsza Polityka Prywatności określa zasady przetwarzania danych osobowych oraz wykorzystywania plików cookies w związku z korzystaniem ze strony internetowej https://gpswiss.pl/.</p>
                        <p>Administratorem danych osobowych jest właściciel serwisu GPSwiss.pl (dalej: „Administrator”).</p>
                        <p>W sprawach związanych z przetwarzaniem danych osobowych można kontaktować się poprzez adres e-mail podany w regulaminie: <a href="https://gpswiss.pl/regulamin-platnosci/">https://gpswiss.pl/regulamin-platnosci/</a></p>

                        <hr>

                        <h2>2. Zakres przetwarzanych danych</h2>
                        <p>W zależności od sposobu korzystania z serwisu przetwarzane mogą być następujące dane:</p>
                        <ul>
                            <li>imię i nazwisko,</li>
                            <li>adres e-mail,</li>
                            <li>numer telefonu,</li>
                            <li>adres dostawy,</li>
                            <li>dane do faktury,</li>
                            <li>adres IP,</li>
                            <li>dane dotyczące zamówień,</li>
                            <li>dane konta użytkownika,</li>
                            <li>dane pozyskane z logowania przez Google (jeśli użytkownik skorzysta z tej opcji).</li>
                        </ul>

                        <hr>

                        <h2>3. Cele przetwarzania danych</h2>
                        <p>Dane osobowe przetwarzane są w celu:</p>
                        <ul>
                            <li>realizacji zamówień i obsługi klienta,</li>
                            <li>prowadzenia konta użytkownika,</li>
                            <li>obsługi płatności,</li>
                            <li>kontaktu z użytkownikiem,</li>
                            <li>realizacji obowiązków prawnych (np. podatkowych),</li>
                            <li>zapewnienia bezpieczeństwa serwisu,</li>
                            <li>prowadzenia statystyk,</li>
                            <li>integracji z platformą Allegro (w zakresie realizacji sprzedaży),</li>
                            <li>umożliwienia logowania przez Google (OAuth).</li>
                        </ul>

                        <hr>

                        <h2>4. Logowanie przez Google</h2>
                        <p>Serwis umożliwia logowanie oraz rejestrację za pomocą konta Google.</p>
                        <p>W przypadku skorzystania z tej funkcji mogą być pobierane następujące dane:</p>
                        <ul>
                            <li>adres e-mail,</li>
                            <li>identyfikator konta Google (sub),</li>
                            <li>imię i nazwisko (jeśli dostępne),</li>
                            <li>zdjęcie profilowe (opcjonalnie),</li>
                            <li>informacja o weryfikacji adresu e-mail.</li>
                        </ul>
                        <p>Dane te są wykorzystywane wyłącznie w celu:</p>
                        <ul>
                            <li>utworzenia konta użytkownika,</li>
                            <li>logowania użytkownika,</li>
                            <li>powiązania konta Google z kontem w serwisie.</li>
                        </ul>

                        <hr>

                        <h2>5. Udostępnianie danych</h2>
                        <p>Dane mogą być przekazywane podmiotom trzecim wyłącznie w zakresie niezbędnym do realizacji usług:</p>
                        <ul>
                            <li>operatorom płatności,</li>
                            <li>firmom kurierskim i logistycznym,</li>
                            <li>dostawcom usług hostingowych,</li>
                            <li>dostawcom narzędzi analitycznych,</li>
                            <li>platformie Allegro (w zakresie realizacji zamówień),</li>
                            <li>Google (w przypadku korzystania z logowania Google).</li>
                        </ul>

                        <hr>

                        <h2>6. Pliki cookies</h2>
                        <p>Strona wykorzystuje pliki cookies w celu:</p>
                        <ul>
                            <li>prawidłowego działania serwisu,</li>
                            <li>utrzymania sesji użytkownika,</li>
                            <li>obsługi logowania,</li>
                            <li>zapamiętywania preferencji,</li>
                            <li>analizy ruchu na stronie.</li>
                        </ul>
                        <p>Użytkownik może w każdej chwili zmienić ustawienia cookies w swojej przeglądarce.</p>

                        <hr>

                        <h2>7. Okres przechowywania danych</h2>
                        <p>Dane osobowe przechowywane są przez okres:</p>
                        <ul>
                            <li>trwania umowy (konto użytkownika),</li>
                            <li>wymagany przepisami prawa (np. księgowość),</li>
                            <li>do momentu wycofania zgody (jeśli dotyczy),</li>
                            <li>niezbędny do realizacji celów przetwarzania.</li>
                        </ul>

                        <hr>

                        <h2>8. Prawa użytkownika</h2>
                        <p>Użytkownik ma prawo do:</p>
                        <ul>
                            <li>dostępu do swoich danych,</li>
                            <li>ich sprostowania,</li>
                            <li>usunięcia („prawo do bycia zapomnianym”),</li>
                            <li>ograniczenia przetwarzania,</li>
                            <li>przenoszenia danych,</li>
                            <li>wniesienia sprzeciwu,</li>
                            <li>złożenia skargi do Prezesa UODO.</li>
                        </ul>

                        <hr>

                        <h2>9. Zabezpieczenia danych</h2>
                        <p>Administrator stosuje odpowiednie środki techniczne i organizacyjne w celu ochrony danych osobowych przed ich utratą, nieuprawnionym dostępem lub ujawnieniem.</p>

                        <hr>

                        <h2>10. Zmiany polityki prywatności</h2>
                        <p>Polityka prywatności może być aktualizowana. Nowa wersja będzie publikowana na tej stronie.</p>

                        <hr>

                        <h2>11. Postanowienia końcowe</h2>
                        <p>Korzystanie z serwisu oznacza akceptację niniejszej Polityki Prywatności.</p>
                    </div>
                <?php endif; ?>
            </article>
        <?php endwhile; ?>
    </div>
</main>
<?php get_footer(); ?>
