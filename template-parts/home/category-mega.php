<?php if (!defined('ABSPATH')) { exit; }

$categories = [
    'Pozostałe' => ['Akcesoria montażowe', 'Elementy złączne', 'Narzędzia warsztatowe', 'Zestawy naprawcze'],
    'Instalacje klimatyzacyjne i grzewcze' => ['Sprężarki klimatyzacji', 'Chłodnice klimatyzacji', 'Nagrzewnice', 'Przewody klimatyzacji'],
    'Układ dolotowy i paliwowy' => ['Przepustnice', 'Pompy paliwa', 'Wtryskiwacze', 'Kolektory dolotowe'],
    'Układ hamulcowy i części' => ['Zaciski hamulcowe', 'Pompy hamulcowe', 'Tarcze i klocki', 'Czujniki ABS'],
    'Samochodowe układy elektryczne' => ['Alternatory', 'Rozruszniki', 'Sterowniki ECU', 'Wiązki elektryczne'],
    'Zawieszenie, układ kierowniczy' => ['Amortyzatory', 'Maglownice', 'Wahacze', 'Drążki kierownicze'],
    'Chłodzenia silnika' => ['Chłodnice wody', 'Wentylatory', 'Termostaty', 'Pompy wody'],
    'Układy wydechowe i oczyszczanie spalin' => ['Katalizatory', 'DPF/FAP', 'Tłumiki', 'Czujniki NOx'],
    'Silniki i części' => ['Kompletne silniki', 'Głowice', 'Wały korbowe', 'Turbosprężarki'],
    'Karoseria, mocowania i akcesoria' => ['Błotniki', 'Maski', 'Pas przedni', 'Mocowania zderzaka'],
    'Układy zapłonowe i żarowe' => ['Cewki zapłonowe', 'Świece żarowe', 'Moduły zapłonowe', 'Przewody WN'],
    'Wyposażenie wnętrza samochodu' => ['Fotele', 'Konsola środkowa', 'Pasy bezpieczeństwa', 'Poduszki powietrzne'],
    'Oświetlenie samochodowe' => ['Reflektory przednie', 'Lampy tylne', 'Przetwornice xenon', 'Moduły LED'],
    'Skrzynie biegów i napędy' => ['Skrzynie manualne', 'Skrzynie automatyczne', 'Półosie napędowe', 'Sprzęgła'],
    'Felgi i opony' => ['Felgi aluminiowe', 'Felgi stalowe', 'Opony letnie', 'Czujniki ciśnienia TPMS'],
    'Czyszczenie reflektorów i szyb' => ['Dysze spryskiwaczy', 'Zbiorniki płynu', 'Wycieraczki', 'Silniczki wycieraczek'],
    'Części do samochodów elektrycznych i hybryd' => ['Przetwornice', 'Falowniki', 'Baterie HV', 'Przewody wysokiego napięcia'],
    'Haki holownicze i części' => ['Belki haka', 'Moduły haka', 'Wiązki elektryczne haka', 'Gniazda 13-pin'],
    'Paski klinowe, żebrowane i części' => ['Paski wielorowkowe', 'Napinacze', 'Rolki prowadzące', 'Koła pasowe'],
    'Układy wspomagania kierowcy' => ['Radary ACC', 'Kamera cofania', 'Czujniki parkowania', 'Sterowniki ADAS'],
    'Systemy transportowe' => ['Bagażniki dachowe', 'Boxy dachowe', 'Uchwyty rowerowe', 'Belki poprzeczne'],
];
?>
<section class="gp-category-mega">
    <div class="gp-container">
        <h2 class="gp-section-title">Wybierz kategorię</h2>
        <div class="gp-category-columns">
            <?php foreach ($categories as $title => $items) : ?>
                <div class="gp-category-block">
                    <h3><a href="#"><?php echo esc_html($title); ?></a></h3>
                    <ul>
                        <?php foreach ($items as $item) : ?>
                            <li><a href="#"><?php echo esc_html($item); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
