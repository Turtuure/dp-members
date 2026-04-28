<?php
$memberPageTitle = 'Jäsenedut';
$memberPageIntro = 'Aktiivisen jäsenyyden konkreettiset edut lyhyesti.';
ob_start();
?>
<h2>Mikä kaikki sinulle kuuluu</h2>
<ul>
    <li><strong>Projektit</strong> — voit perustaa projektiehdotuksen ja osallistua muiden projekteihin.</li>
    <li><strong>Foorumi</strong> — kirjoita topicceja ja vastaa keskusteluihin.</li>
    <li><strong>Tapahtumat</strong> — ilmoittaudu jäsentapahtumiin ennen avointa ilmoittautumista.</li>
    <li><strong>Päätöksenteko</strong> — äänioikeus vuosikokouksessa.</li>
    <li><strong>Yhteisö</strong> — pääsy yhteisön sisäisiin viestintäkanaviin ja pöytäkirjoihin.</li>
</ul>

<h2>Jäsenyyden ylläpito</h2>
<p>Jäsenmaksu laskutetaan vuosittain. Muistutus tulee sähköpostiin viimeistään kaksi viikkoa ennen eräpäivää. Jäsenyys pysyy voimassa kun maksu on suoritettu <a href="/bylaws">sääntöjen</a> §4:n mukaisesti.</p>

<h2>Jäsenen oikeudet ja velvollisuudet</h2>
<p>Tarkemmat määräykset löytyvät <a href="/bylaws">yhdistyksen säännöistä</a>. Eettisiä rikkomuksia voi ilmoittaa <a href="/ethical-reporting">eettisen ilmoituskanavan</a> kautta.</p>
<?php
$memberPageBody = ob_get_clean();
require __DIR__ . '/_layout.php';
