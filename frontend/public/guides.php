<?php
$memberPageTitle = 'Sisäiset ohjeet';
$memberPageIntro = 'Käytännöt, joilla yhteisö toimii.';
ob_start();
?>
<h2>Projektit</h2>
<p>Voit ehdottaa uutta projektia <a href="/projects/new">Projektit → Ehdota projektia</a> -sivulta. Ehdotus menee hallituksen käsiteltäväksi. Kun se hyväksytään, sinusta tulee projektin omistaja ja voit kutsua muut jäsenet mukaan.</p>

<h2>Foorumi</h2>
<ul>
    <li>Pysy aiheessa ja kunnioita muita.</li>
    <li>Raportoi sopimaton sisältö liputus-ikonilla (<i class="bi bi-flag"></i> <em>Raportoi</em>).</li>
    <li>Moderaattorit käsittelevät raportit backstagessa (ks. <a href="/ethical-reporting">Eettinen ilmoituskanava</a>).</li>
</ul>

<h2>Tapahtumat</h2>
<p>Jäsenille avataan tapahtumiin ilmoittautuminen ensin. Avoin ilmoittautuminen alkaa myöhemmin. Seuraa <a href="/events">Tapahtumat</a>-sivua.</p>

<h2>Yhteys hallitukseen</h2>
<p>Ota yhteyttä <code>hallitus@daems.fi</code>. Kiireellisissä eettisissä ilmoituksissa käytä ensisijaisesti <a href="/ethical-reporting">eettistä ilmoituskanavaa</a>.</p>
<?php
$memberPageBody = ob_get_clean();
require __DIR__ . '/_layout.php';
