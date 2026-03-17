<?php
require_once('wp-load.php');
$pages = [
  [
    'post_title'   => 'Privacy Policy',
    'post_name'    => 'privacy-policy',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Titolare del trattamento: La Redazione di Domizio News. Email: privacy@domizionews.it
Dati raccolti: Questo sito non raccoglie dati personali degli utenti in modo diretto. Vengono utilizzati cookie tecnici necessari al funzionamento del sito e, previo consenso, cookie di profilazione pubblicitaria tramite Google AdSense.
Cookie pubblicitari: Questo sito utilizza Google AdSense per la pubblicazione di annunci pubblicitari. Google può utilizzare cookie per mostrare annunci basati sulle visite precedenti. Per maggiori informazioni: google.com/settings/ads
Diritti dell utente: L utente può richiedere informazioni, rettifica o cancellazione dei propri dati scrivendo a privacy@domizionews.it
Base giuridica: Il trattamento è basato sul consenso dell utente (art. 6 GDPR) per i cookie pubblicitari, e sul legittimo interesse per i cookie tecnici.
Ultimo aggiornamento: marzo 2025',
  ],
  [
    'post_title'   => 'Note Legali',
    'post_name'    => 'note-legali',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Proprietà del sito: Domizio News (domizionews.it) è una testata giornalistica online non registrata, gestita da La Redazione di Domizio News.
Contenuti: I contenuti pubblicati su questo sito sono generati automaticamente da fonti pubbliche tramite sistemi di intelligenza artificiale. La redazione non è responsabile di eventuali imprecisioni. Per segnalazioni: privacy@domizionews.it
Proprietà intellettuale: I contenuti originali del sito sono di proprietà di Domizio News. È vietata la riproduzione senza autorizzazione.
Limitazione di responsabilità: Domizio News non si assume responsabilità per eventuali danni derivanti dall uso del sito o dei contenuti pubblicati.
Foro competente: Per qualsiasi controversia è competente il Tribunale di Caserta.',
  ],
  [
    'post_title'   => 'Cookie Policy',
    'post_name'    => 'cookie-policy',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Cosa sono i cookie: I cookie sono piccoli file di testo che i siti visitati salvano sul dispositivo dell utente.
Cookie tecnici (sempre attivi): Necessari al funzionamento del sito. Non richiedono consenso.
Cookie pubblicitari (previo consenso): Utilizzati da Google AdSense per mostrare annunci pertinenti. Google può utilizzare questi cookie per personalizzare gli annunci in base alla navigazione dell utente. Per gestire le preferenze: google.com/settings/ads - Per opt-out: aboutads.info
Come gestire i cookie: Puoi modificare le preferenze sui cookie in qualsiasi momento tramite il banner presente sul sito, oppure dalle impostazioni del tuo browser.
Contatti: privacy@domizionews.it
Ultimo aggiornamento: marzo 2025',
  ],
  [
    'post_title'   => 'Contatti',
    'post_name'    => 'contatti',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Domizio News è una testata giornalistica online che copre le notizie del Litorale Domizio e dei comuni di Mondragone, Castel Volturno, Baia Domizia, Cellole, Falciano del Massico, Carinola e Sessa Aurunca.
Email redazione: redazione@domizionews.it
Segnalazioni e rettifiche: Per segnalare inesattezze o richiedere la rimozione di contenuti scrivere a redazione@domizionews.it',
  ],
  [
    'post_title'   => 'Chi Siamo',
    'post_name'    => 'chi-siamo',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Domizio News è una testata giornalistica online dedicata alle notizie del Litorale Domizio, in provincia di Caserta.
Copriamo i comuni di Mondragone, Castel Volturno, Baia Domizia, Cellole, Falciano del Massico, Carinola e Sessa Aurunca con aggiornamenti quotidiani su cronaca, eventi, cultura e attualità locale.
La nostra missione: Portare le notizie del territorio direttamente ai cittadini, in modo rapido, accessibile e gratuito.
Come lavoriamo: Le notizie vengono raccolte automaticamente dalle principali fonti locali e nazionali tramite tecnologia di intelligenza artificiale, e revisionate dalla redazione prima della pubblicazione.
Contatti: redazione@domizionews.it',
  ],
  [
    'post_title'   => 'Disclaimer',
    'post_name'    => 'disclaimer',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => 'Contenuti generati con intelligenza artificiale: Gli articoli pubblicati su Domizio News sono elaborati con il supporto di sistemi di intelligenza artificiale (AI) a partire da fonti giornalistiche pubbliche. La redazione verifica i contenuti prima della pubblicazione, ma non garantisce l assoluta accuratezza delle informazioni.
Limitazione di responsabilità: Domizio News non si assume responsabilità per eventuali errori, omissioni o imprecisioni nei contenuti pubblicati. Per segnalare un errore: privacy@domizionews.it
Fonti: I contenuti sono elaborati a partire da agenzie di stampa, testate giornalistiche locali e nazionali e comunicati ufficiali di enti pubblici.
Diritto di rettifica: Chiunque si ritenga danneggiato da un contenuto pubblicato può richiederne la rettifica o la rimozione scrivendo a privacy@domizionews.it. La redazione risponderà entro 48 ore.
Proprietà intellettuale: I contenuti originali elaborati dalla redazione sono di proprietà di Domizio News. È vietata la riproduzione senza autorizzazione scritta.',
  ],
];
foreach ($pages as $page) {
  if (!get_page_by_path($page['post_name'])) {
    wp_insert_post($page);
    echo "Created: " . $page['post_title'] . "\n";
  } else {
    echo "Already exists: " . $page['post_title'] . "\n";
  }
}
echo "Done\n";
