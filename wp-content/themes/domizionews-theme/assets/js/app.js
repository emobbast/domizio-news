/* Domizio News App — standalone bundle (UMD, no build required)
   Carica React da CDN tramite importmap nel template. */

(function () {

  // ─── CONFIG: legge da window.DNAPP_CONFIG iniettato da WordPress ────────────
  const CFG = window.DNAPP_CONFIG || {};
  const API = CFG.wpBase ? CFG.wpBase.replace(/\/$/, '') : '';
  const CUSTOM_API  = API.replace('/wp/v2', '') + '/dnapp/v1';
  const DOMIZIO_API = API.replace('/wp/v2', '') + '/domizio/v1';
  const STICKY_API  = DOMIZIO_API + '/sticky-news';

  // Slug WordPress page pubblicati come pagine legali. Duplica la lista servita
  // da SSR/footer: usata per routing URL (pushState su click, popstate su back,
  // e rilevamento iniziale quando l'utente atterra direttamente su /<slug>/).
  const LEGAL_SLUGS = ['chi-siamo','contatti','privacy-policy','cookie-policy','note-legali','disclaimer'];

  // ─── UTILS ──────────────────────────────────────────────────────────────────
  function timeAgo(date) {
    const diff = Date.now() - new Date(date).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 2) return 'Ora';
    if (mins < 60) return mins + 'm fa';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h fa';
    return Math.floor(hrs / 24) + 'g fa';
  }

  // Hydrate every [data-timestamp] node in the given root (or document) by
  // replacing its text with the relative timeAgo() label. Decision A: the SSR
  // emits an absolute date as the initial label and the ISO string in the
  // [data-timestamp] attribute — so it stays meaningful even when the page is
  // page-cached and served stale. After SPA boot (and on every render() that
  // re-emits SPA cards) we walk the DOM and rewrite each label to the correct
  // relative form. Cards inserted later (e.g. by the .dn-load-more handler)
  // also call this on the new nodes.
  function hydrateTimestamps(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const nodes = scope.querySelectorAll ? scope.querySelectorAll('[data-timestamp]') : [];
    nodes.forEach(n => {
      const iso = n.getAttribute('data-timestamp');
      if (!iso) return;
      const t = new Date(iso).getTime();
      if (Number.isNaN(t)) return;
      n.textContent = timeAgo(iso);
    });
  }

  function stripHtml(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return d.textContent || '';
  }

  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function decodeHtml(str) {
    const d = document.createElement('div');
    d.innerHTML = str || '';
    return d.textContent || '';
  }

  // ─── CANONICAL / TITLE MANAGEMENT ───────────────────────────────────────────
  const _origTitle = document.title;
  const _origCanonicalEl = document.querySelector('link[rel="canonical"]');
  const _origCanonicalHref = _origCanonicalEl ? _origCanonicalEl.href : '';

  function updateArticleHead(post) {
    document.title = decodeHtml(post.title) + ' | Domizio News';
    let canonical = document.querySelector('link[rel="canonical"]');
    if (!canonical) {
      canonical = document.createElement('link');
      canonical.rel = 'canonical';
      document.head.appendChild(canonical);
    }
    canonical.href = post.permalink || 'https://domizionews.it/';
  }

  function restoreHead() {
    document.title = _origTitle;
    const canonical = document.querySelector('link[rel="canonical"]');
    if (canonical) {
      if (_origCanonicalHref) {
        canonical.href = _origCanonicalHref;
      } else {
        canonical.remove();
      }
    }
  }

  // ─── STATE ──────────────────────────────────────────────────────────────────
  let state = {
    tab: 'home',
    selectedPost: null,
    posts: [],
    cities: [],
    categories: [],
    stickyNews: [],
    loading: true,
    searchQuery: '',
    selectedCity: '',
    selectedCat: '',
    activeHomeCat:  '',     // slug chip categoria attivo nella home ('' = Tutte)
    homeCityPosts:  {},     // map city-slug → posts[] caricati al boot per "Tutte"
    homeCatPosts:   {},     // map city-slug → posts[] filtrati per categoria attiva
    homeCatLoading: false,  // spinner mentre si caricano post per categoria
    cityFeed: [],           // post caricati server-side per la città selezionata (tab Città)
    cityFeedLoading: false, // spinner mentre si aspetta la risposta
    cityPage: 1,            // pagina corrente del feed città (1-based) — pilota "Vedi altro"
    cityFeedTotalPages: 1,  // totale pagine disponibili dal REST (max_num_pages)
    scopriStep:      'categorie', // 'categorie' | 'risultati'
    scopriCategoria: null,        // slug categoria Scopri selezionata
    scopriCity:      'tutte',     // slug città Scopri ('tutte' = nessun filtro)
    scopriResults:   [],          // risultati da /domizio/v1/scopri
    scopriLoading:   false,
    searchMode:      false,  // true = header trasformato in barra di ricerca
    selectedLegalPage: null,
  };

  // Firma della "vista" corrente: serve a distinguere un cambio di view (tab,
  // articolo aperto, pagina legale, modalità cerca, città selezionata, chip
  // categoria home, step Scopri) dai render che aggiornano solo dati
  // (feed, risultati ricerca mentre si digita, spinner). Solo i cambi di
  // view devono resettare lo scroll al top.
  let lastViewSig = '';

  function viewSig(s) {
    if (!s) return '';
    return [
      s.tab || '',
      s.selectedPost ? 'p:' + s.selectedPost.id : '',
      s.selectedLegalPage || '',
      s.searchMode ? 'sm' : '',
      'c:' + (s.selectedCity || ''),
      'hc:' + (s.activeHomeCat || ''),
      'sc:' + (s.scopriStep || '') + ':' + (s.scopriCategoria || '') + ':' + (s.scopriCity || ''),
    ].join('|');
  }

  function scrollToTopIfViewChanged(newState, oldState) {
    const newSig = viewSig(newState);
    const oldSig = viewSig(oldState);
    if (newSig !== oldSig) {
      window.scrollTo({ top: 0, behavior: 'auto' });
      document.documentElement.scrollTop = 0;
      document.body.scrollTop = 0;
      const root = document.getElementById('domizionews-root');
      if (root) root.scrollTop = 0;
    }
    lastViewSig = newSig;
  }

  // Snapshot/restore dello scrollLeft delle chip-bar orizzontali attraverso
  // ogni render(): `render()` riscrive innerHTML e il nuovo container parte
  // con scrollLeft=0, facendo "saltare" il chip attivo fuori vista se era
  // stato scrollato a destra. Salviamo prima del render e riapplichiamo dopo
  // via rAF sul nuovo nodo (stesso indice all'interno della sua classe).
  // Container di classi diverse vengono salvati separatamente, così uno
  // switch di tab (home ↔ città) non riapplica il valore sbagliato.
  function snapshotChipScroll() {
    const snap = {};
    document.querySelectorAll('.dn-home-chips').forEach((el, i) => { snap['home_' + i] = el.scrollLeft; });
    document.querySelectorAll('.dn-chips-scroll').forEach((el, i) => { snap['scroll_' + i] = el.scrollLeft; });
    return snap;
  }
  function restoreChipScroll(snap) {
    document.querySelectorAll('.dn-home-chips').forEach((el, i) => {
      const v = snap['home_' + i];
      if (v != null) el.scrollLeft = v;
    });
    document.querySelectorAll('.dn-chips-scroll').forEach((el, i) => {
      const v = snap['scroll_' + i];
      if (v != null) el.scrollLeft = v;
    });
  }

  function setState(patch) {
    const oldState = state;
    const chipScroll = snapshotChipScroll();
    state = Object.assign({}, state, patch);
    // Qualsiasi state change uscita dal click di un utente esce dalla
    // hydration mode: il prossimo render() legittimamente sostituisce il
    // SSR con la view SPA (non è un flash automatico — è un'azione
    // user-initiated). loadData() usa una via alternativa (mutazione
    // diretta di state) per popolare i dati senza triggherare render.
    state.hydrated = false;
    render();
    requestAnimationFrame(() => restoreChipScroll(chipScroll));
    scrollToTopIfViewChanged(state, oldState);
    syncBrowsingTitle();
  }

  // ─── API ────────────────────────────────────────────────────────────────────

  // Slug esatti registrati nel database — usati sia per la home che per il tab Città
  // 'cellole-baia-domizia' e 'falciano-carinola' sono slug virtuali: caricano entrambe le città
  const CITY_SLUGS = [
    'mondragone',
    'castel-volturno',
    'cellole-baia-domizia',   // sezione unificata Cellole + Baia Domizia
    'falciano-carinola',      // sezione unificata Falciano del Massico + Carinola
    'sessa-aurunca',
  ];

  // Nomi visualizzati per slug (incluso quello virtuale)
  const CITY_SLUG_LABELS = {
    'mondragone':           'Mondragone',
    'castel-volturno':      'Castel Volturno',
    'cellole':              'Cellole',
    'baia-domizia':         'Baia Domizia',
    'cellole-baia-domizia': 'Cellole e Baia Domizia',
    'falciano-del-massico': 'Falciano del Massico',
    'carinola':             'Carinola',
    'falciano-carinola':    'Falciano e Carinola',
    'sessa-aurunca':        'Sessa Aurunca',
  };

  // City slugs that are virtual aggregates (multiple physical cities
  // grouped in a single section). Used to filter them out from UI
  // surfaces that list individual cities (e.g. the "Città" tab chip bar).
  const AGGREGATE_CITY_SLUGS = ['cellole-baia-domizia', 'falciano-carinola'];

  // Titolo fallback della home: viene ripristinato quando nessuna città e
  // nessun chip-categoria sono selezionati. Replicato qui perché _origTitle
  // (catturato al boot) può contenere il titolo SSR di una vista non-home
  // quando l'utente atterra direttamente su /citta/<slug>/ o /category/<slug>/
  const HOME_DEFAULT_TITLE = 'Domizio News — Notizie dal Litorale Domizio';

  function capitalizeFirst(s) {
    if (!s) return '';
    const clean = String(s).replace(/-/g, ' ');
    return clean.charAt(0).toUpperCase() + clean.slice(1);
  }

  // Aggiorna document.title in base alla vista corrente. Chiamato dopo ogni
  // setState(), dal boot() iniziale e dal popstate. Ramificazioni:
  //   - selectedPost → lasciato a updateArticleHead()
  //   - selectedLegalPage → lasciato al flow esistente (titolo SSR)
  //   - tab=cities + selectedCity → "<Nome città> | Domizio News"
  //   - tab=home + activeHomeCat → "<Nome categoria> | Domizio News"
  //   - default → HOME_DEFAULT_TITLE
  function syncBrowsingTitle() {
    if (state.selectedPost)      return;
    if (state.selectedLegalPage) return;
    if (state.tab === 'cities' && state.selectedCity) {
      const label = CITY_SLUG_LABELS[state.selectedCity] || capitalizeFirst(state.selectedCity);
      document.title = label + ' | Domizio News';
      return;
    }
    if (state.tab === 'home' && state.activeHomeCat) {
      const cat = HOME_CATEGORIES.find(c => c.slug === state.activeHomeCat);
      const name = cat ? cat.name : capitalizeFirst(state.activeHomeCat);
      document.title = name + ' | Domizio News';
      return;
    }
    document.title = HOME_DEFAULT_TITLE;
  }

  // Carica post filtrati per città (tab Città).
  // GET /wp-json/domizio/v1/posts?city=SLUG&page=N&per_page=20
  // append=true: nuove card concatenate a state.cityFeed (usato dalla "Vedi altro" SPA).
  // append=false: replace puro (caso default — selezione città / boot).
  async function loadCityFeed(slug, page = 1, append = false) {
    if (!slug) {
      setState({ cityFeed: [], cityFeedLoading: false, cityPage: 1, cityFeedTotalPages: 1 });
      return;
    }
    const url = DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug)
      + '&page=' + page + '&per_page=20';
    console.log('[DomizioNews] fetch città:', url);
    try {
      const res  = await fetch(url);
      const data = await res.json();
      const newPosts   = data.posts || [];
      const totalPages = data.total_pages ? parseInt(data.total_pages, 10) : 1;
      if (append) {
        setState({
          cityFeed:           state.cityFeed.concat(newPosts),
          cityFeedLoading:    false,
          cityPage:           page,
          cityFeedTotalPages: totalPages,
        });
      } else {
        setState({
          cityFeed:           newPosts,
          cityFeedLoading:    false,
          cityPage:           page,
          cityFeedTotalPages: totalPages,
        });
      }
    } catch (e) {
      console.error('[DomizioNews] errore fetch città:', e);
      setState({ cityFeedLoading: false });
    }
  }

  // Carica post filtrati per categoria (chip home).
  // Parallel fetch per CITY_SLUGS — REST handles aggregate union server-side,
  // così homeCatPosts è keyed direttamente dagli slug di CITY_SLUGS (incluse
  // le aggregate) e buildHome può leggere uniformemente.
  async function loadCategoryFeed(catSlug) {
    if (!catSlug) {
      setState({ activeHomeCat: '', homeCatPosts: {}, homeCatLoading: false });
      return;
    }
    setState({ activeHomeCat: catSlug, homeCatLoading: true });
    try {
      const results = await Promise.all(
        CITY_SLUGS.map(slug =>
          fetch(DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug)
            + '&category=' + encodeURIComponent(catSlug) + '&per_page=5')
            .then(r => r.json())
            .catch(() => ({ posts: [] }))
        )
      );
      const grouped = {};
      CITY_SLUGS.forEach((slug, i) => {
        grouped[slug] = (results[i]?.posts || []).slice(0, 3);
      });
      setState({ homeCatPosts: grouped, homeCatLoading: false });
    } catch (e) {
      console.error('[DomizioNews] errore fetch categoria home:', e);
      setState({ homeCatLoading: false });
    }
  }

  async function loadData() {
    try {
      // Fetch feed principale, config, sticky news e i feed città in parallelo.
      // REST honors aggregate slugs natively (server-side post union),
      // so a single fetch covers both individual and aggregate cities.
      const fetchCityPosts = (slug) => {
        return fetch(DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug) + '&per_page=5')
          .then(r => r.json())
          .catch(() => ({ posts: [] }));
      };

      const [feed, config, sticky, ...cityResults] = await Promise.all([
        fetch(CUSTOM_API + '/feed?per_page=20').then(r => r.json()),
        fetch(CUSTOM_API + '/config').then(r => r.json()),
        fetch(STICKY_API).then(r => r.ok ? r.json() : []).catch(() => []),
        ...CITY_SLUGS.map(slug => fetchCityPosts(slug)),
      ]);

      const homeCityPosts = {};
      CITY_SLUGS.forEach((slug, i) => {
        homeCityPosts[slug] = cityResults[i]?.posts || [];
      });

      const patch = {
        posts:         feed.posts || [],
        cities:        config.cities || [],
        categories:    config.categories || [],
        stickyNews:    Array.isArray(sticky) ? sticky : [],
        homeCityPosts: homeCityPosts,
        loading:       false,
      };

      // In hydration mode (landing su archive SSR) scriviamo state
      // direttamente per evitare render() che flasherebbe il SSR fuori.
      // Al primo click user → setState → exit hydration → render con
      // dati già popolati: nessun flash, nessuna UI vuota.
      if (state.hydrated) {
        Object.assign(state, patch);
      } else {
        setState(patch);
      }
    } catch (e) {
      console.error('Errore API:', e);
      if (state.hydrated) {
        state.loading = false;
      } else {
        setState({ loading: false });
      }
    }
  }

  // ─── AD SLOTS ────────────────────────────────────────────────────────────────
  const AD_CONFIG = {
    enabled: true,
    adsenseClientId: 'ca-pub-6979338420884576',
    slots: {
      'home-feed': {
        id:           'home-feed',
        enabled:      true,
        adsenseSlot:  '6860504195',
        adsenseFormat: 'fluid',
        adsenseLayoutKey: '-6t+d2-39-3c+r7',
        admobUnitId:  null,
      },
      'article-bottom': {
        id:           'article-bottom',
        enabled:      true,
        adsenseSlot:  '3708427948',
        adsenseFormat: 'fluid',
        adsenseLayout: 'in-article',
        admobUnitId:  null,
      },
      'banner-nav': {
        id:           'banner-nav',
        enabled:      true,
        adsenseSlot:  '7559376761',
        adsenseFormat: 'auto',
        adsenseFullWidth: true,
        admobUnitId:  null,
      },
    },
  };

  function renderAd(slotId) {
    if (!AD_CONFIG.enabled) return '';
    const slot = AD_CONFIG.slots[slotId];
    if (!slot || !slot.enabled) return '';
    // ── Future: AdMob (Capacitor native) ─────────────────
    // if (window.Capacitor && slot.admobUnitId) {
    //   AdMob.showBanner({ adId: slot.admobUnitId });
    //   return '';
    // }
    // ── AdSense (web) ─────────────────────────────────────
    const layoutKey = slot.adsenseLayoutKey
      ? `data-ad-layout-key="${slot.adsenseLayoutKey}"`
      : '';
    const layout = slot.adsenseLayout
      ? `data-ad-layout="${slot.adsenseLayout}"`
      : '';
    const fullWidth = slot.adsenseFullWidth
      ? `data-full-width-responsive="true"`
      : '';
    const style = slotId === 'banner-nav'
      ? 'display:block;'
      : slotId === 'article-bottom'
      ? 'display:block;text-align:center;'
      : 'display:block;';
    const slotClassMap = {
      'banner-nav':     'dn-ad-banner',
      'home-feed':      'dn-ad-infeed',
      'article-bottom': 'dn-ad-article',
    };
    const extraClass = slotClassMap[slotId] || '';
    return `
      <div class="dn-ad-card ${extraClass}">
        <span class="dn-ad-badge">ADV</span>
        <ins class="adsbygoogle"
             style="${style}"
             data-ad-client="${AD_CONFIG.adsenseClientId}"
             data-ad-slot="${slot.adsenseSlot}"
             data-ad-format="${slot.adsenseFormat}"
             ${layoutKey}
             ${layout}
             ${fullWidth}>
        </ins>
      </div>
    `;
  }

  function initAds() {
    try {
      const ads = document.querySelectorAll('.adsbygoogle:not([data-adsbygoogle-status])');
      ads.forEach(() => {
        (window.adsbygoogle = window.adsbygoogle || []).push({});
      });
    } catch(e) {}
  }

  // ─── CARD BADGES ─────────────────────────────────────────────────────────────
  function buildCardBadges(post) {
    const cat  = post.categories?.[0];
    const city = post.cities?.[0];
    if (!cat && !city) return '';
    return `
      <div class="dn-card-badges">
        ${cat  ? `<span class="dn-cat-label">${escHtml(decodeHtml(cat.name))}</span>` : ''}
        ${city ? `<span class="dn-city-label">${escHtml(decodeHtml(city.name))}</span>` : ''}
      </div>`;
  }

  // ─── HTML BUILDERS ──────────────────────────────────────────────────────────

  function buildImagePlaceholder() {
    return `
      <div style="background:#EADDFF;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;">
        <span class="material-symbols-outlined" style="font-size:48px;color:#6750A4;">article</span>
      </div>`;
  }

  // Hero card: immagine full-width 16/9
  // isLast = true → nessun border-bottom (evita doppio bordo con separatore sezione)
  function buildHeroCard(post, isLast) {
    const img    = post.image || '';
    const altTxt = escHtml(decodeHtml(post.title));
    return `
      <article class="dn-card-hero${isLast ? ' dn-card-last' : ''}" data-post-id="${post.id}">
        <div class="dn-card-hero-img">${img ? `<img src="${img}" alt="${altTxt}" loading="eager">` : buildImagePlaceholder()}</div>
        <div class="dn-card-hero-body">
          ${buildCardBadges(post)}
          <h3 class="dn-card-hero-title">${escHtml(decodeHtml(post.title))}</h3>
          <span class="dn-time" data-timestamp="${escHtml(post.date)}">${timeAgo(post.date)}</span>
        </div>
      </article>`;
  }

  // List card: thumbnail 80x80 a destra
  // isLast = true → nessun border-bottom
  function buildArticleCard(post, isLast) {
    const img    = post.image || '';
    const altTxt = escHtml(decodeHtml(post.title));
    const thumbPlaceholder = `<div style="background:#EADDFF;width:80px;height:80px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:32px;color:#6750A4;">article</span></div>`;
    return `
      <article class="dn-card-list${isLast ? ' dn-card-last' : ''}" data-post-id="${post.id}">
        <div class="dn-card-body">
          ${buildCardBadges(post)}
          <h3>${escHtml(decodeHtml(post.title))}</h3>
          <span class="dn-time" data-timestamp="${escHtml(post.date)}">${timeAgo(post.date)}</span>
        </div>
        ${img ? `<img src="${img}" alt="${altTxt}" loading="lazy">` : thumbPlaceholder}
      </article>`;
  }

  // ─── SEARCH: debounce timer (modulo-level, sopravvive ai re-render) ──────────
  let searchDebounceTimer = null;
  let searchRequestToken  = 0;
  let searchResults       = []; // ultimi post ricevuti dal backend — serve alla delega click

  // ─── SCOPRI: costanti ────────────────────────────────────────────────────────
  const SCOPRI_CATEGORIES = [
    { nome: 'Ristoranti & Locali',    slug: 'ristoranti-locali',    icon: 'restaurant' },
    { nome: 'Eventi & Concerti',      slug: 'eventi-concerti',      icon: 'event' },
    { nome: 'Spiagge & Stabilimenti', slug: 'spiagge-stabilimenti', icon: 'beach_access' },
    { nome: 'Immobiliare',            slug: 'immobiliare',          icon: 'home' },
    { nome: 'Negozi',                 slug: 'negozi',               icon: 'storefront' },
    { nome: 'Food & Gusto',           slug: 'food-gusto',           icon: 'lunch_dining' },
    { nome: 'Turismo & Vacanze',      slug: 'turismo-vacanze',      icon: 'luggage' },
    { nome: 'Shopping',               slug: 'shopping',             icon: 'shopping_bag' },
    { nome: 'Benessere',              slug: 'benessere',            icon: 'spa' },
  ];

  const SCOPRI_CITIES = [
    { slug: 'tutte',                name: 'Tutte' },
    { slug: 'mondragone',           name: 'Mondragone' },
    { slug: 'castel-volturno',      name: 'Castel Volturno' },
    { slug: 'baia-domizia',         name: 'Baia Domizia' },
    { slug: 'cellole',              name: 'Cellole' },
    { slug: 'falciano-del-massico', name: 'Falciano' },
    { slug: 'carinola',             name: 'Carinola' },
    { slug: 'sessa-aurunca',        name: 'Sessa Aurunca' },
  ];

  // Carica risultati Scopri da /wp-json/domizio/v1/scopri?categoria=SLUG&city=SLUG
  async function loadScopriResults(categoria, city) {
    setState({ scopriStep: 'risultati', scopriCategoria: categoria, scopriCity: city || 'tutte', scopriResults: [], scopriLoading: true });
    try {
      let url = DOMIZIO_API + '/scopri?categoria=' + encodeURIComponent(categoria);
      if (city && city !== 'tutte') url += '&city=' + encodeURIComponent(city);
      const data = await fetch(url).then(r => r.json()).catch(() => ({ results: [] }));
      setState({ scopriResults: data.results || [], scopriLoading: false });
    } catch (e) {
      console.error('[DomizioNews] errore fetch scopri:', e);
      setState({ scopriLoading: false });
    }
  }

  // ─── CHIP CATEGORIE (home) ───────────────────────────────────────────────────
  // Nota: i chip sono per categoria notizie (cronaca, sport…), NON per città.
  // Le città sono le sezioni nella home; i chip filtrano per categoria editoriale.
  const HOME_CATEGORIES = [
    { slug: '',                name: 'Tutte' },
    { slug: 'cronaca',         name: 'Cronaca' },
    { slug: 'sport',     name: 'Sport' },
    { slug: 'politica',  name: 'Politica' },
    { slug: 'economia',  name: 'Economia' },
    { slug: 'ambiente',  name: 'Ambiente' },
    { slug: 'eventi',    name: 'Eventi' },
    { slug: 'salute',    name: 'Salute' },
    // 'food-gusto', 'turismo-vacanze', 'shopping', 'benessere' rimossi:
    // appartengono alla tassonomia scopri_categoria (CPT scopri), non alle
    // categorie editoriali delle notizie.
  ];

  // ─── HEADER ─────────────────────────────────────────────────────────────────
  // Normale: lente a sinistra · "Domizio News" centrato · avatar "D" a destra
  // Search mode: freccia ← + input full-width (non controllato)
  function buildHeader() {
    if (state.searchMode) {
      return `
        <header class="dn-top-header dn-search-active">
          <button class="dn-search-back-btn" id="dn-search-back" aria-label="Indietro">
            <span class="material-symbols-outlined" style="font-size:24px;color:#202124;">arrow_back</span>
          </button>
          <input id="dn-search-input" type="search" placeholder="Cerca argomenti, località e fonti" autocomplete="off">
        </header>`;
    }
    return `
      <header class="dn-top-header">
        <button class="dn-header-btn" id="dn-header-search" aria-label="Cerca">
          <span class="material-symbols-outlined" style="font-size:24px;color:#6750A4;">search</span>
        </button>
        <div class="dn-logo" id="dn-logo-home" style="cursor:pointer;">
          <span class="dn-logo-domizio">Domizio</span>
          <span class="dn-logo-news">news</span>
        </div>
        <div class="dn-header-avatar">D</div>
      </header>`;
  }

  function buildCategoryChipsBar() {
    return `
      <div class="dn-home-chips" id="dn-cat-chips">
        ${HOME_CATEGORIES.map(c => `
          <button class="dn-home-chip ${state.activeHomeCat === c.slug ? 'active' : ''}" data-home-cat="${c.slug}">${c.name}</button>
        `).join('')}
      </div>`;
  }

  // ─── SLIDER NOTIZIE IN EVIDENZA ──────────────────────────────────────────────
  // Usa i dati da /wp-json/domizio/v1/sticky-news (una card per città).
  // Fallback: ultime notizie generiche se l'endpoint non ha risposto.
  function buildSlider() {
    const items = state.stickyNews.length > 0
      ? state.stickyNews
      : state.posts.slice(0, 5).map(p => ({
          post_id:   p.id,
          title:     p.title,
          image:     p.image || '',
          category:  p.categories?.[0]?.name || '',
          city:      p.cities?.[0]?.name || '',
          city_slug: p.cities?.[0]?.slug || '',
          time_ago:  timeAgo(p.date),
          permalink: p.source_url || '',
          is_vip:    !!p.sticky,
        }));

    // Deduplica per post_id — mantieni solo la prima occorrenza
    const seen = new Set();
    const uniqueItems = items.filter(item => {
      if (!item.post_id || seen.has(item.post_id)) return false;
      seen.add(item.post_id);
      return true;
    });

    if (uniqueItems.length === 0) return '';

    return `
      <div class="dn-slider-wrap">
        <div class="dn-slider" id="dn-slider">
          ${uniqueItems.map(item => `
            <div class="dn-slider-card" data-sticky-href="${item.permalink}" data-post-id="${item.post_id}">
              <div class="dn-slider-img">${item.image ? `<img src="${item.image}" alt="${escHtml(decodeHtml(item.title))}" loading="eager">` : buildImagePlaceholder()}</div>
              <div class="dn-slider-body">
                <div class="dn-card-badges">
                  ${item.category ? `<span class="dn-cat-label">${escHtml(decodeHtml(item.category))}</span>` : ''}
                  ${item.city     ? `<span class="dn-city-label">${escHtml(decodeHtml(item.city))}</span>` : ''}
                </div>
                <h3 class="dn-slider-title">${escHtml(decodeHtml(item.title))}</h3>
                <span class="dn-time">${item.time_ago}</span>
              </div>
            </div>`).join('')}
        </div>
        <div class="dn-slider-dots" id="dn-slider-dots">
          ${uniqueItems.map((_, i) => `<span class="dn-dot ${i === 0 ? 'active' : ''}"></span>`).join('')}
        </div>
      </div>`;
  }

  // ─── SEARCH OVERLAY: vista unica indipendente dal tab attivo ─────────────────
  // Renderizzato da render() quando state.searchMode === true, prima del
  // dispatch per tab. Copre Home/Città/Scopri uniformemente così la ricerca
  // funziona da qualsiasi tab. Chiudendo (freccia ←) si resta sul tab corrente.
  function buildSearchOverlay() {
    return `
      <div class="dn-screen" id="screen-search">
        ${buildHeader()}
        <main>
          <div class="dn-feed" id="dn-search-results">
            <p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>
          </div>
        </main>
      </div>`;
  }

  // ─── HOME: sezioni per città filtrate per categoria ──────────────────────────
  function buildHome() {
    const activeCat = state.activeHomeCat; // '' = Tutte

    let citySections = '';
    if (state.homeCatLoading) {
      citySections = `<div style="display:flex;justify-content:center;padding:40px 16px"><div class="dn-spinner"></div></div>`;
    } else {
      let cityCount = 0;
      CITY_SLUGS.forEach(slug => {
        const label = CITY_SLUG_LABELS[slug] || slug;
        // "Tutte" → homeCityPosts (caricati al boot)
        // Categoria specifica → homeCatPosts (raggruppati per città)
        let cityPosts;
        if (activeCat) {
          cityPosts = state.homeCatPosts[slug] || [];
        } else {
          cityPosts = state.homeCityPosts[slug] || [];
        }
        if (cityPosts.length === 0) return;
        cityCount++;
        if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
          citySections += renderAd('home-feed');
        }
        const shown = cityPosts.slice(0, 3);
        citySections += `
          <section class="dn-city-section" id="city-section-${slug}">
            <div class="dn-section-label" data-goto-city="${slug}">${label}</div>
            <div class="dn-feed">
              ${shown.map((p, idx) => {
                const isLast = idx === shown.length - 1;
                return idx === 0 ? buildHeroCard(p, isLast) : buildArticleCard(p, isLast);
              }).join('')}
            </div>
            <div class="dn-city-more-wrap">
              <button class="dn-city-more" data-goto-city="${slug}"><span class="material-symbols-outlined" style="font-size:18px;">newspaper</span>Vedi altro</button>
            </div>
          </section>
          <div class="dn-section-separator"></div>`;
      });
      if (cityCount === 0) {
        citySections = `<p class="dn-empty" style="padding:40px 16px">Nessuna notizia per questa categoria.</p>`;
      }
    }

    return `
      <div class="dn-screen" id="screen-home">
        ${buildHeader()}
        <main>
          ${buildCategoryChipsBar()}
          ${buildSlider()}
          ${citySections}
        </main>
      </div>`;
  }

  function buildFooter() {
    return `
      <footer class="dn-footer">
        <div class="dn-footer-links">
          <a href="/chi-siamo/" data-legal="chi-siamo">Chi Siamo</a>
          <a href="/contatti/" data-legal="contatti">Contatti</a>
          <a href="/privacy-policy/" data-legal="privacy-policy">Privacy Policy</a>
          <a href="/cookie-policy/" data-legal="cookie-policy">Cookie Policy</a>
          <a href="/note-legali/" data-legal="note-legali">Note Legali</a>
          <a href="/disclaimer/" data-legal="disclaimer">Disclaimer</a>
        </div>
        <p class="dn-footer-copy">© 2026 Domizio News</p>
      </footer>`;
  }

  function buildCities() {
    // Il feed per città viene caricato server-side (loadCityFeed) così vengono
    // trovati anche i post di città piccole che non rientrano nei primi 20.
    let feedHtml;
    if (!state.selectedCity) {
      feedHtml = state.posts.length === 0
        ? `<p class="dn-empty" style="padding:40px 16px">Nessuna notizia disponibile.</p>`
        : state.posts.map(p => buildArticleCard(p)).join('');
    } else if (state.cityFeedLoading) {
      feedHtml = `<div style="display:flex;justify-content:center;padding:40px 16px"><div class="dn-spinner"></div></div>`;
    } else if (state.cityFeed.length === 0) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Nessun articolo per questa città.</p>`;
    } else {
      feedHtml = state.cityFeed.map(p => buildArticleCard(p)).join('');
      // "Vedi altro" — riusa il delegate handler .dn-load-more (stesse data-*
      // attributes del SSR archive). href punta all'URL paginato SSR per
      // progressive enhancement (Googlebot/condivisione/Ctrl+click).
      if (state.cityFeedTotalPages > state.cityPage) {
        const nextPage = state.cityPage + 1;
        const slug     = state.selectedCity;
        feedHtml += `
          <div class="dn-city-more-wrap">
            <a class="dn-load-more dn-city-more"
               href="/citta/${escHtml(slug)}/page/${nextPage}/"
               data-archive-type="city"
               data-archive-slug="${escHtml(slug)}"
               data-next-page="${nextPage}"><span class="material-symbols-outlined" style="font-size:18px;">newspaper</span>Vedi altro</a>
          </div>`;
      }
    }

    return `
      <div class="dn-screen">
        ${buildHeader()}
        <div class="dn-detail-header">
          <div style="width:32px;"></div>
          <span style="font-size:16px;font-weight:500;color:var(--color-text);">${
            state.selectedCity
              ? escHtml(CITY_SLUG_LABELS[state.selectedCity] || capitalizeFirst(state.selectedCity))
              : 'Città'
          }</span>
          <div style="width:32px;"></div>
        </div>
        <main>
          <div class="dn-chips-scroll">
            ${state.cities
              .filter(c => !AGGREGATE_CITY_SLUGS.includes(c.slug))
              .map(c => `
                <button class="dn-chip ${state.selectedCity === c.slug ? 'active' : ''}" data-city="${escHtml(c.slug)}">${escHtml(c.name)}</button>
              `).join('')}
          </div>
          <div class="dn-feed">
            ${feedHtml}
          </div>
        </main>
      </div>`;
  }

  // ─── SCOPRI: builder ─────────────────────────────────────────────────────────
  function buildScopriAttivitaCard(item) {
    const hasBtns = item.phone || item.whatsapp || item.website;
    return `
      <div class="dn-card-attivita">
        ${item.image ? `<div class="dn-card-attivita-img"><img src="${item.image}" alt="" loading="lazy"></div>` : ''}
        <div class="dn-card-attivita-body">
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
            <span class="dn-badge-attivita">Attività</span>
            ${item.city ? `<span class="dn-city-label">${escHtml(decodeHtml(item.city))}</span>` : ''}
          </div>
          ${item.price_range ? `<div class="dn-card-attivita-price">${escHtml(item.price_range)}</div>` : ''}
          <div class="dn-card-attivita-title">${escHtml(decodeHtml(item.title))}</div>
          ${item.address ? `<div class="dn-card-attivita-addr">${escHtml(item.address)}</div>` : ''}
          ${hasBtns ? `
          <div class="dn-card-attivita-btns">
            ${item.phone    ? `<button class="dn-btn-action" data-tel="${escHtml(item.phone)}">Chiama</button>` : ''}
            ${item.whatsapp ? `<button class="dn-btn-action" data-wa="${escHtml(item.whatsapp)}">WhatsApp</button>` : ''}
            ${item.website  ? `<a class="dn-btn-action" href="${escHtml(item.website)}" target="_blank" rel="noopener noreferrer">Sito</a>` : ''}
          </div>` : ''}
        </div>
      </div>`;
  }

  function buildScopriArticoloCard(item) {
    return `
      <div class="dn-card-scopri-art" data-post-id="${item.id}">
        ${item.image ? `<div class="dn-card-scopri-art-img"><img src="${item.image}" alt="" loading="lazy"></div>` : ''}
        <div class="dn-card-scopri-art-body">
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
            <span class="dn-badge-articolo">Articolo</span>
            ${item.city ? `<span class="dn-city-label">${escHtml(decodeHtml(item.city))}</span>` : ''}
          </div>
          <div class="dn-card-scopri-art-title">${escHtml(decodeHtml(item.title))}</div>
          <span class="dn-time">${item.time_ago || ''}</span>
        </div>
      </div>`;
  }

  function buildScopri() {
    if (state.scopriStep === 'categorie') {
      return `
        <div class="dn-screen">
          ${buildHeader()}
          <div class="dn-detail-header">
            <div style="width:32px;"></div>
            <span style="font-size:16px;font-weight:500;color:var(--color-text);">Scopri</span>
            <div style="width:32px;"></div>
          </div>
          <main>
            <div class="dn-scopri-grid">
              ${SCOPRI_CATEGORIES.map(c => `
                <div class="dn-scopri-card" data-scopri-cat="${c.slug}">
                  <span class="material-symbols-outlined" style="font-size:48px;color:#5F6368;">${c.icon}</span>
                  <div class="dn-scopri-card-name">${c.nome}</div>
                </div>
              `).join('')}
            </div>
          </main>
        </div>`;
    }

    // STEP 2 — risultati
    const cat = SCOPRI_CATEGORIES.find(c => c.slug === state.scopriCategoria);
    const catNome = cat ? cat.nome : state.scopriCategoria;

    let feedHtml;
    if (state.scopriLoading) {
      feedHtml = `<div style="display:flex;justify-content:center;padding:40px 16px"><div class="dn-spinner"></div></div>`;
    } else if (state.scopriResults.length === 0) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Nessun contenuto disponibile per questa categoria.</p>`;
    } else {
      feedHtml = state.scopriResults.map(item =>
        item.type === 'attivita'
          ? buildScopriAttivitaCard(item)
          : buildScopriArticoloCard(item)
      ).join('');
    }

    return `
      <div class="dn-screen">
        ${buildHeader()}
        <div class="dn-detail-header">
          <button class="dn-back-btn" data-scopri-back>
            <span class="material-symbols-outlined">arrow_back</span>
          </button>
          <span style="font-size:16px;font-weight:500;color:var(--color-text);">${catNome}</span>
          <div style="width:32px;"></div>
        </div>
        <main>
          <div class="dn-chips-scroll" style="padding-top:12px">
            ${SCOPRI_CITIES.map(c => `
              <button class="dn-chip ${state.scopriCity === c.slug ? 'active' : ''}" data-scopri-city="${c.slug}">${c.name}</button>
            `).join('')}
          </div>
          <div class="dn-feed">${feedHtml}</div>
        </main>
      </div>`;
  }

  function buildSearch() {
    // L'input è NON controllato: non ha l'attributo value e non viene mai
    // ricreato durante la digitazione. I risultati vengono aggiornati dalla
    // delega globale 'input' (setupGlobalDelegation) tramite patch diretta
    // del DOM con debounce 300ms, evitando il reset del cursore su mobile.
    return `
      <div class="dn-screen">
        <div class="dn-page-header"><h2>Cerca</h2></div>
        <div style="padding: 0 16px 16px">
          <div class="dn-search-wrap">
            <input id="dn-search-input" type="search" placeholder="Cerca notizie..." autocomplete="off">
          </div>
        </div>
        <div class="dn-feed" id="dn-search-results">
          <p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>
        </div>
      </div>`;
  }

  async function renderSearchResults(q) {
    const resultsEl = document.getElementById('dn-search-results');
    if (!resultsEl) return;
    if (q.length < 2) {
      resultsEl.innerHTML = `<p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>`;
      return;
    }
    // Token-based race guard: se parte una query nuova mentre la precedente è in volo,
    // la risposta vecchia viene scartata.
    const myToken = ++searchRequestToken;
    resultsEl.innerHTML = `<div style="display:flex;justify-content:center;padding:40px 16px"><div class="dn-spinner"></div></div>`;
    const url = CUSTOM_API + '/feed?search=' + encodeURIComponent(q) + '&per_page=20';
    const data = await fetch(url).then(r => r.json()).catch(() => ({ posts: [] }));
    if (myToken !== searchRequestToken) return;
    const results = data.posts || [];
    searchResults = results;
    resultsEl.innerHTML = results.length === 0
      ? `<p class="dn-empty" style="padding:60px 16px 0">Nessun risultato per "<b>${escHtml(q)}</b>"</p>`
      : `<p style="font-size:13px;color:#5F6368;padding:0 16px 8px">${results.length} risultati</p>
         ${results.map(p => buildArticleCard(p)).join('')}`;
    // Click handler sulle card: nessun bind locale — li cattura la delega globale su [data-post-id].
  }

  function buildArticleDetail(post) {
    const date = new Date(post.date).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
    return `
      <main class="dn-detail">
        <div class="dn-detail-header">
          <div class="dn-detail-header-left">
            <button class="dn-back-btn" id="dn-back">Indietro</button>
            <div class="dn-logo" id="dn-article-logo" style="cursor:pointer;">
              <span class="dn-logo-domizio">Domizio</span>
              <span class="dn-logo-news">news</span>
            </div>
          </div>
          <button class="dn-share-btn" id="dn-share">Condividi</button>
        </div>
        <div class="dn-detail-img-wrap">
          ${post.image ? `<img src="${post.image}" alt="${escHtml(decodeHtml(post.title))}">` : buildImagePlaceholder()}
        </div>
        ${post.unsplash_credit ? `<div class="dn-photo-credit">${post.unsplash_credit}</div>` : ''}
        <article class="dn-detail-body">
          <div class="dn-badges">
            ${post.categories?.map(c => `<span class="dn-badge-cat">${escHtml(decodeHtml(c.name))}</span>`).join('') || ''}
            ${post.cities?.map(c => `<span class="dn-badge-city">${escHtml(decodeHtml(c.name))}</span>`).join('') || ''}
          </div>
          <h1 class="dn-detail-title">${escHtml(decodeHtml(post.title))}</h1>
          <div class="dn-detail-byline">
            <div class="dn-avatar">R</div>
            <div>
              <div class="dn-byline-name">Redazione</div>
              <div class="dn-byline-date">${date}</div>
            </div>
          </div>
          <div class="dn-detail-content">${post.content}</div>
          ${renderAd('article-bottom')}
        </article>
      </main>`;
  }

  async function buildLegalPage(slug) {
    try {
      const res = await fetch(`${API}/pages?slug=${slug}&_fields=title,content`);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!data || !data.length) throw new Error('Page not found');
      const page = data[0];
      return `
        <div class="dn-legal-page">
          <div class="dn-detail-header">
            <button class="dn-back-btn" data-action="back-legal">
              <span class="material-symbols-outlined">arrow_back</span>
            </button>
            <span style="font-size:16px;font-weight:500;color:var(--color-text);">${escHtml(page.title.rendered)}</span>
            <div style="width:32px;"></div>
          </div>
          <div class="dn-legal-content">
            ${page.content.rendered}
          </div>
        </div>
      `;
    } catch(e) {
      return `
        <div class="dn-legal-page">
          <div class="dn-detail-header">
            <button class="dn-back-btn" data-action="back-legal">
              <span class="material-symbols-outlined">arrow_back</span>
            </button>
          </div>
          <div style="padding:32px 16px;text-align:center;color:#5F6368;">
            Contenuto non disponibile.
          </div>
        </div>
      `;
    }
  }

  function buildNav() {
    if (state.searchMode) return ''; // bottom nav nascosta in modalità cerca

    const tabs = [
      { id: 'home',       label: 'Home',  icon: 'home' },
      { id: 'cities',     label: 'Città', icon: 'location_city' },
      { id: 'categories', label: 'Scopri', icon: 'explore' },
    ];
    return `
      <nav class="dn-bottom-nav">
        ${tabs.map(t => {
          const isActive = state.tab === t.id;
          return `
          <button class="dn-nav-tab ${isActive ? 'active' : ''}" data-tab="${t.id}">
            <div class="dn-nav-icon-wrap ${isActive ? 'active' : ''}">
              <span class="material-symbols-outlined">${t.icon}</span>
            </div>
            <span class="dn-nav-label">${t.label}</span>
          </button>`;
        }).join('')}
      </nav>`;
  }

  function buildLoading() {
    return `
      <div class="dn-loading">
        <div class="dn-logo" style="position:relative;margin-bottom:32px;">
          <span class="dn-logo-domizio">Domizio</span>
          <span class="dn-logo-news">news</span>
        </div>
        <div class="dn-spinner"></div>
      </div>`;
  }

  // ─── STYLES ─────────────────────────────────────────────────────────────────
  const STYLES = ''; // Migrated to base.css — keep var so render() interpolation stays valid.

  // ─── RENDER ─────────────────────────────────────────────────────────────────
  function render() {
    const root = document.getElementById('domizionews-root');
    if (!root) return;

    let content = '';

    if (state.loading) {
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildLoading()}</div>`;
      return;
    }

    if (state.selectedPost) {
      updateArticleHead(state.selectedPost);
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app" style="padding-bottom:0">${buildArticleDetail(state.selectedPost)}</div>`;
      initAds();
      return;
    }

    if (state.selectedLegalPage) {
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}${buildLoading()}${buildNav()}</div>`;
      buildLegalPage(state.selectedLegalPage).then(html => {
        root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}${html}${buildNav()}</div>`;
        initAds();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }).catch(() => {
        root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}<div style="padding:32px 16px;text-align:center;color:#5F6368;">Contenuto non disponibile.<br><br><button class="dn-back-btn" data-action="back-legal" style="color:#6750A4;">← Torna indietro</button></div>${buildNav()}</div>`;
      });
      return;
    }

    // Search mode: overlay top-level, tab-agnostico. Il tab corrente resta in
    // state.tab, quindi chiudendo la ricerca l'utente torna esattamente dove era.
    if (state.searchMode) {
      content = buildSearchOverlay();
    } else {
      if (state.tab === 'home')       content = buildHome();
      if (state.tab === 'cities')     content = buildCities();
      if (state.tab === 'categories') content = buildScopri();
      if (state.tab === 'search')     content = buildSearch();
    }

    root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${content}${buildFooter()}${renderAd('banner-nav')}${buildNav()}</div>`;
    // Quando l'input di ricerca compare (header search mode o tab Cerca), dagli focus.
    // La delega globale di 'input' è già wirata una volta sola su root — non serve re-bindare.
    document.getElementById('dn-search-input')?.focus();
    initAds();
    hydrateTimestamps(root);
  }

  // Delegazione globale: attaccata UNA VOLTA sola a #domizionews-root durante boot().
  // Ogni render() riscrive innerHTML, ma il listener su root sopravvive e cattura
  // qualsiasi click/input su elementi data-* o ID noti — anche futuri rami di render
  // che non erano previsti in origine.
  function setupGlobalDelegation() {
    const root = document.getElementById('domizionews-root');
    if (!root) return;

    // ── Handler dispatch per click: ogni ramo usa closest() per trovare
    //    l'ancestor interattivo e poi esegue il medesimo corpo originale.
    root.addEventListener('click', (e) => {
      const t = e.target;

      // ── "Vedi altro" — progressive enhancement ────────────────────────────
      // Il bottone è renderizzato sia dal SSR archive (index.php, ora con la
      // stessa shape SPA: .dn-feed > .dn-card-list) sia dal SPA buildCities.
      // Single code path: fetch REST, append cards via buildArticleCard,
      // pushState dell'URL, niente full reload. Ctrl/Cmd/Shift+click per
      // apertura nativa in nuova tab.
      const loadMore = t.closest('.dn-load-more');
      if (loadMore) {
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();

        const nextPage = parseInt(loadMore.dataset.nextPage, 10);
        const type     = loadMore.dataset.archiveType; // 'city' | 'category'
        const slug     = loadMore.dataset.archiveSlug;
        if (!nextPage || !slug || !type) return;

        const origLabel = loadMore.textContent;
        loadMore.textContent = 'Caricamento...';
        loadMore.style.opacity = '0.6';

        const param = type === 'city' ? 'city' : 'category';
        const url   = `${DOMIZIO_API}/posts?${param}=${encodeURIComponent(slug)}&page=${nextPage}&per_page=20`;
        fetch(url)
          .then(r => r.ok ? r.json() : null)
          .then(data => {
            const posts = (data && data.posts) ? data.posts : [];
            if (posts.length === 0) {
              loadMore.remove();
              return;
            }
            const container = loadMore.parentElement;
            if (!container) return;

            const tmp = document.createElement('div');
            tmp.innerHTML = posts.map(p => buildArticleCard(p)).join('');
            const inserted = Array.from(tmp.children);
            inserted.forEach(card => container.insertBefore(card, loadMore));
            // I nuovi nodi hanno [data-timestamp] dal builder — re-hydrate
            // così le label si aggiornano subito (timeAgo invece della data
            // assoluta SSR).
            inserted.forEach(hydrateTimestamps);

            // Se siamo nel SPA City tab, sincronizza lo state senza setState
            // (no re-render → preserva scroll position).
            if (type === 'city' && state.tab === 'cities' && state.selectedCity === slug) {
              state.cityFeed = state.cityFeed.concat(posts);
              state.cityPage = nextPage;
            }

            const totalPages = data && data.total_pages ? parseInt(data.total_pages, 10) : nextPage;
            if (type === 'city' && state.tab === 'cities' && state.selectedCity === slug) {
              state.cityFeedTotalPages = totalPages;
            }
            if (nextPage >= totalPages || posts.length < 20) {
              loadMore.remove();
            } else {
              loadMore.dataset.nextPage = nextPage + 1;
              const basePath = type === 'city'
                ? '/citta/' + slug + '/page/' + (nextPage + 1) + '/'
                : '/category/' + slug + '/page/' + (nextPage + 1) + '/';
              loadMore.setAttribute('href', basePath);
              loadMore.textContent = origLabel || 'Vedi altro';
              loadMore.style.opacity = '1';
            }

            const curPath = type === 'city'
              ? '/citta/' + slug + '/page/' + nextPage + '/'
              : '/category/' + slug + '/page/' + nextPage + '/';
            history.pushState(
              { archiveType: type, archiveSlug: slug, page: nextPage },
              '',
              curPath
            );
          })
          .catch(() => {
            loadMore.textContent = 'Errore — riprova';
            loadMore.style.opacity = '1';
          });
        return;
      }

      // Back button da legal page (usa data-action per distinguere da altre back)
      if (t.closest('[data-action="back-legal"]')) {
        setState({ selectedLegalPage: null });
        history.pushState(null, '', '/');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Detail view: Indietro
      if (t.closest('#dn-back')) {
        restoreHead();
        setState({ selectedPost: null });
        history.pushState(null, '', '/');
        return;
      }

      // Detail view: Condividi
      if (t.closest('#dn-share')) {
        const post = state.selectedPost;
        if (!post) return;
        const shareData = {
          title: post.title,
          text:  post.excerpt || post.title,
          url:   post.permalink || window.location.href,
        };
        if (navigator.share) {
          navigator.share(shareData).catch(() => {});
        } else {
          navigator.clipboard?.writeText(shareData.url).then(() => {
            alert('Link copiato negli appunti');
          }).catch(() => {});
        }
        return;
      }

      // Header: click logo → torna alla home
      // SSR emette il logo come <a href="/"> per JS-off; in hydration mode
      // serve preventDefault() per restare in SPA (no full reload).
      if (t.closest('#dn-logo-home')) {
        e.preventDefault();
        setState({ tab: 'home', selectedPost: null, selectedLegalPage: null, searchMode: false });
        history.pushState({}, '', '/');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Detail view: click logo → torna alla home (anche qui SSR usa <a>).
      if (t.closest('#dn-article-logo')) {
        e.preventDefault();
        restoreHead();
        setState({ tab: 'home', selectedPost: null, selectedLegalPage: null, searchMode: false });
        history.pushState({}, '', '/');
        return;
      }

      // Header: click icona lente → attiva search mode
      if (t.closest('#dn-header-search')) {
        setState({ searchMode: true });
        return;
      }

      // Header search mode: freccia ← → torna alla vista normale
      if (t.closest('#dn-search-back')) {
        clearTimeout(searchDebounceTimer);
        setState({ searchMode: false });
        return;
      }

      // Pulsante indietro tab Città → torna alla Home
      if (t.closest('#btn-back-home')) {
        setState({ tab: 'home' });
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Footer legal links — aggiorna URL così il link è condivisibile/indicizzabile
      const legal = t.closest('[data-legal]');
      if (legal) {
        e.preventDefault();
        const slug = legal.dataset.legal;
        setState({ selectedLegalPage: slug, selectedPost: null });
        history.pushState({ legalPage: slug }, '', '/' + slug + '/');
        return;
      }

      // Scopri — bottone Indietro (step 2 → step 1)
      if (t.closest('[data-scopri-back]')) {
        setState({ scopriStep: 'categorie', scopriCategoria: null, scopriResults: [], scopriLoading: false });
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Scopri — click su card categoria (step 1 → step 2)
      const scopriCat = t.closest('[data-scopri-cat]');
      if (scopriCat) {
        loadScopriResults(scopriCat.dataset.scopriCat, 'tutte');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Scopri — chip città (step 2)
      const scopriCity = t.closest('[data-scopri-city]');
      if (scopriCity) {
        const slug = scopriCity.dataset.scopriCity;
        if (slug === state.scopriCity) return;
        loadScopriResults(state.scopriCategoria, slug);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Scopri — bottoni Chiama / WhatsApp (card attività)
      const tel = t.closest('[data-tel]');
      if (tel) {
        window.location.href = 'tel:' + tel.dataset.tel;
        return;
      }
      const wa = t.closest('[data-wa]');
      if (wa) {
        window.open('https://wa.me/' + wa.dataset.wa.replace(/\D/g, ''), '_blank');
        return;
      }

      // Chip categorie (home) — "Tutte" resetta, le altre caricano dal server.
      // SSR emette i chip come <a> con href=/category/<slug>/ (o "/" per Tutte);
      // preventDefault qui evita il full reload in hydration mode.
      const homeCat = t.closest('[data-home-cat]');
      if (homeCat) {
        e.preventDefault();
        const slug = homeCat.dataset.homeCat;
        if (slug === state.activeHomeCat) return; // stesso chip: nessuna azione
        if (!slug) {
          // "Tutte": ripristina le sezioni per città caricate al boot
          setState({ activeHomeCat: '', homeCatPosts: {}, homeCatLoading: false });
          history.pushState({}, '', '/');
        } else {
          loadCategoryFeed(slug);
          history.pushState({ categoryPage: slug }, '', '/category/' + slug + '/');
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Section label città cliccabile + "Vedi altro" → tab Città
      // SSR emette section label e bottone come <a> con href=/citta/<slug>/.
      const gotoCity = t.closest('[data-goto-city]');
      if (gotoCity) {
        e.preventDefault();
        const slug = gotoCity.dataset.gotoCity;
        setState({
          tab: 'cities',
          selectedCity: slug,
          cityFeed: [],
          cityFeedLoading: true,
          cityPage: 1,
          cityFeedTotalPages: 1,
        });
        loadCityFeed(slug);
        history.pushState({ cityPage: slug }, '', '/citta/' + slug + '/');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // City chips (tab Città) — SSR usa <a href=/citta/slug/>.
      const cityChip = t.closest('[data-city]');
      if (cityChip) {
        e.preventDefault();
        const slug    = cityChip.dataset.city;
        const newSlug = state.selectedCity === slug ? '' : slug;
        setState({
          selectedCity: newSlug,
          cityFeed: [],
          cityFeedLoading: !!newSlug,
          cityPage: 1,
          cityFeedTotalPages: 1,
        });
        loadCityFeed(newSlug);
        if (newSlug) {
          history.pushState({ cityPage: newSlug }, '', '/citta/' + newSlug + '/');
        } else {
          // Deselezione: niente city attiva → feed generale. Non esiste
          // un archivio /citta/ root, torniamo a '/' per un URL canonico.
          history.pushState({}, '', '/');
        }
        return;
      }

      // Category tiles (tab Scopri legacy — non più usato, mantenuto per sicurezza)
      const catChip = t.closest('[data-cat]');
      if (catChip) {
        const slug = catChip.dataset.cat;
        setState({ selectedCat: state.selectedCat === slug ? '' : slug });
        return;
      }

      // Bottom nav — SSR usa <a> con href reale; preventDefault per restare in SPA.
      const tabEl = t.closest('[data-tab]');
      if (tabEl) {
        e.preventDefault();
        setState({ tab: tabEl.dataset.tab, selectedLegalPage: null });
        return;
      }

      // Article cards (feed principale, city feed, slider, ricerca, sticky)
      // IMPORTANTE: data-post-id va per ULTIMO perché più generico — alcuni
      // wrapper esterni (es. section label) potrebbero non essere post ma
      // contenere card annidate con data-post-id più in profondità.
      // SSR card = <a href="permalink">; SPA card = <article> (no href).
      // Se troviamo il post nello state, e.preventDefault() per restare in SPA;
      // altrimenti lasciamo che l'anchor href navighi normalmente (post fuori
      // dai feed pre-caricati: full reload è il fallback corretto).
      const postCard = t.closest('[data-post-id]');
      if (postCard) {
        const id   = postCard.dataset.postId;
        const post = state.posts.find(p => p.id == id)
                  || state.cityFeed.find(p => p.id == id)
                  || Object.values(state.homeCityPosts).flat().find(p => p.id == id)
                  || Object.values(state.homeCatPosts).flat().find(p => p.id == id)
                  || state.scopriResults.find(p => p.type === 'articolo' && p.id == id)
                  || searchResults.find(p => p.id == id);
        if (post) {
          e.preventDefault();
          setState({ selectedPost: post });
          if (post.permalink) {
            history.pushState({ postId: post.id }, post.title, post.permalink);
          }
        } else if (postCard.dataset.stickyHref) {
          e.preventDefault();
          window.location.href = postCard.dataset.stickyHref;
        }
        // Else: nessun post in state, nessuno stickyHref → la card SSR ha href
        // sul permalink reale; la navigazione browser nativa è il fallback.
        return;
      }
    });

    // ── Input delegato: Option A — deleghiamo anche 'input' sulla root.
    //    L'<input id="dn-search-input"> viene ricreato da ogni render() perché
    //    innerHTML lo sostituisce; delegare evita di ri-bindare dopo ogni render.
    //    L'handler NON chiama render(): aggiorna solo #dn-search-results per non
    //    ricreare l'input e resettare il cursore su mobile.
    root.addEventListener('input', (e) => {
      if (e.target && e.target.id === 'dn-search-input') {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
          renderSearchResults(e.target.value);
        }, 300);
      }
    });

    // ── Slider — aggiorna dots allo scroll. L'evento scroll non bolle, quindi
    //    usiamo la capture phase su root per intercettarlo comunque.
    root.addEventListener('scroll', (e) => {
      if (!e.target || e.target.id !== 'dn-slider') return;
      const slider = e.target;
      const dotsEl = document.getElementById('dn-slider-dots');
      if (!dotsEl) return;
      const cardEl = slider.firstElementChild;
      if (!cardEl) return;
      const cardWidth = cardEl.offsetWidth + 12; // card + gap
      const index = Math.min(
        Math.round(slider.scrollLeft / cardWidth),
        dotsEl.children.length - 1
      );
      Array.from(dotsEl.children).forEach((dot, i) => {
        dot.classList.toggle('active', i === index);
      });
    }, { capture: true, passive: true });

    // ── Horizontal scroll via mouse wheel sui container chip ────────────────
    root.addEventListener('wheel', (e) => {
      const el = e.target.closest('.dn-home-chips, .dn-chips-scroll');
      if (!el) return;
      e.preventDefault();
      el.scrollLeft += e.deltaY;
    }, { passive: false });

    // ── Click-to-drag sui container chip ────────────────────────────────────
    //    Stato tenuto in chiusura locale: un drag alla volta è sufficiente
    //    (l'utente non può trascinare due container simultaneamente).
    let dragEl = null;
    let dragStartX = 0;
    let dragScrollLeft = 0;

    root.addEventListener('mousedown', (e) => {
      const el = e.target.closest('.dn-home-chips, .dn-chips-scroll');
      if (!el) return;
      dragEl = el;
      dragStartX = e.pageX - el.offsetLeft;
      dragScrollLeft = el.scrollLeft;
      el.style.cursor = 'grabbing';
      el.style.userSelect = 'none';
    });

    // mousemove/mouseup sul document: seguono il puntatore anche fuori dal
    // container (drag naturale — niente più early-stop su mouseleave).
    document.addEventListener('mousemove', (e) => {
      if (!dragEl) return;
      const x = e.pageX - dragEl.offsetLeft;
      dragEl.scrollLeft = dragScrollLeft - (x - dragStartX);
    });

    document.addEventListener('mouseup', () => {
      if (!dragEl) return;
      dragEl.style.cursor = '';
      dragEl.style.userSelect = '';
      dragEl = null;
    });

    // ── History navigation (back del browser dalla detail view) ─────────────
    // Ordine di precedenza:
    //   1. Legal slug (exact match su LEGAL_SLUGS)
    //   2. /citta/<slug>/ o /citta/<slug>/page/N/
    //   3. /category/<slug>/ o /category/<slug>/page/N/
    //   4. articolo singolo (detect via e.state.postId)
    //   5. fallback: home root
    window.onpopstate = (e) => {
      const path = window.location.pathname.replace(/^\/|\/$/g, '');

      if (LEGAL_SLUGS.includes(path)) {
        setState({ selectedPost: null, selectedLegalPage: path });
        return;
      }

      const cityMatch = path.match(/^citta\/([^/]+?)(?:\/page\/(\d+))?$/);
      if (cityMatch) {
        const slug = cityMatch[1];
        setState({
          tab: 'cities',
          selectedCity: slug,
          selectedPost: null,
          selectedLegalPage: null,
          cityFeed: [],
          cityFeedLoading: true,
          cityPage: 1,
          cityFeedTotalPages: 1,
        });
        loadCityFeed(slug);
        return;
      }

      const catMatch = path.match(/^category\/([^/]+?)(?:\/page\/(\d+))?$/);
      if (catMatch) {
        const slug = catMatch[1];
        setState({
          tab: 'home',
          activeHomeCat: slug,
          selectedPost: null,
          selectedLegalPage: null,
          selectedCity: '',
        });
        loadCategoryFeed(slug);
        return;
      }

      if (!e.state || !e.state.postId) {
        setState({
          selectedPost: null,
          selectedLegalPage: null,
          selectedCity: '',
          activeHomeCat: '',
          tab: 'home',
        });
      }
    };
  }

  // ─── BOOT ───────────────────────────────────────────────────────────────────
  function boot() {
    // Initial-load URL detection. Precedenza:
    //   1. Legal slug (match esatto su LEGAL_SLUGS)
    //   2. /citta/<slug>/ o /citta/<slug>/page/N/  (SSR archive → hydration)
    //   3. /category/<slug>/ o /category/<slug>/page/N/ (SSR archive → hydration)
    //   4. fallback: pretty permalink → lookup articolo dopo loadData()
    //
    // HYDRATION MODE: se SSR ha già renderizzato l'archive (attributo marker
    // [data-ssr-archive="city"|"category"] sul wrapper .dn-screen — emesso da
    // index.php), NON chiamiamo render() iniziale — il SSR resta a schermo
    // senza flash. state viene popolato direttamente; loadData() scrive i
    // dati senza setState; "Vedi altro" è intercettato con manipolazione DOM.
    // Al primo click user su un altro elemento (tab, chip, logo), setState
    // setta hydrated=false e render() rimpiazza il SSR con la view SPA —
    // azione user-initiated, non flash.
    const bootPath  = window.location.pathname.replace(/^\/|\/$/g, '');
    const cityBoot  = bootPath.match(/^citta\/([^/]+?)(?:\/page\/(\d+))?$/);
    const catBoot   = bootPath.match(/^category\/([^/]+?)(?:\/page\/(\d+))?$/);
    const isLegal   = LEGAL_SLUGS.includes(bootPath);

    const rootEl        = document.getElementById('domizionews-root');
    const hasSsrArchive = !!(rootEl && rootEl.querySelector('[data-ssr-archive]'));
    const canHydrate    = hasSsrArchive && (cityBoot || catBoot);

    if (canHydrate) {
      state.hydrated = true;
      if (cityBoot) {
        state.tab          = 'cities';
        state.selectedCity = cityBoot[1];
        state.cityPage     = cityBoot[2] ? parseInt(cityBoot[2], 10) : 1;
      } else {
        state.tab           = 'home';
        state.activeHomeCat = catBoot[1];
        state.catPage       = catBoot[2] ? parseInt(catBoot[2], 10) : 1;
      }
      syncBrowsingTitle();
      setupGlobalDelegation();
      // SSR ha emesso .dn-time con data-timestamp + data assoluta come label
      // iniziale. Riscriviamo le label in formato relativo (timeAgo) — utile
      // soprattutto quando la pagina è servita da cache e la data è "vecchia".
      hydrateTimestamps(rootEl);
      // loadData() popola state.cities/categories/posts senza render in
      // hydration mode (vedi ramo if state.hydrated in loadData). Pre-
      // caricare qui garantisce che il primo render post-hydration abbia
      // dati già pronti.
      loadData();
      return;
    }

    if (isLegal) {
      state.selectedLegalPage = bootPath;
    } else if (cityBoot) {
      state.tab             = 'cities';
      state.selectedCity    = cityBoot[1];
      state.cityFeedLoading = true;
    } else if (catBoot) {
      state.tab            = 'home';
      state.activeHomeCat  = catBoot[1];
      state.homeCatLoading = true;
    }

    render();
    syncBrowsingTitle();
    setupGlobalDelegation();
    loadData().then(() => {
      if (cityBoot)  { loadCityFeed(cityBoot[1]);    return; }
      if (catBoot)   { loadCategoryFeed(catBoot[1]); return; }
      if (isLegal)   return;

      // Check if we landed on a pretty permalink (e.g. /titolo-articolo/)
      const path = window.location.pathname;
      if (path && path !== '/' && !path.startsWith('/wp-')) {
        const slug = path.replace(/^\/|\/$/g, '');
        if (slug && !LEGAL_SLUGS.includes(slug)) {
          fetch(`${CUSTOM_API}/feed?slug=${encodeURIComponent(slug)}&per_page=1`)
            .then(r => r.ok ? r.json() : null)
            .then(data => {
              const posts = data?.posts || [];
              if (posts.length > 0) {
                const p = posts.find(x => x.slug === slug) || posts[0];
                setState({ selectedPost: p });
              }
            })
            .catch(() => {});
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();
