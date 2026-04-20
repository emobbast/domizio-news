/* Domizio News App — standalone bundle (UMD, no build required)
   Carica React da CDN tramite importmap nel template. */

(function () {

  // ─── CONFIG: legge da window.DNAPP_CONFIG iniettato da WordPress ────────────
  const CFG = window.DNAPP_CONFIG || {};
  const API = CFG.wpBase ? CFG.wpBase.replace(/\/$/, '') : '';
  const CUSTOM_API  = API.replace('/wp/v2', '') + '/dnapp/v1';
  const DOMIZIO_API = API.replace('/wp/v2', '') + '/domizio/v1';
  const STICKY_API  = DOMIZIO_API + '/sticky-news';

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
    canonical.href = post.source_url || 'https://domizionews.it/';
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

  function setState(patch) {
    const oldState = state;
    state = Object.assign({}, state, patch);
    render();
    scrollToTopIfViewChanged(state, oldState);
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
    'cellole-baia-domizia': 'Cellole e Baia Domizia',
    'falciano-del-massico': 'Falciano del Massico',
    'carinola':             'Carinola',
    'falciano-carinola':    'Falciano e Carinola',
    'sessa-aurunca':        'Sessa Aurunca',
  };

  // Mappa slug virtuali → slug reale DB per navigazione "Vedi altro"
  const CITY_GOTO_TARGET = {
    'cellole-baia-domizia': 'cellole',
    'falciano-carinola':    'falciano-del-massico',
  };

  // Carica post filtrati per città (tab Città).
  // GET /wp-json/domizio/v1/posts?city=SLUG&per_page=20
  async function loadCityFeed(slug) {
    if (!slug) {
      setState({ cityFeed: [], cityFeedLoading: false });
      return;
    }
    const url = DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug) + '&per_page=20';
    console.log('[DomizioNews] fetch città:', url);
    try {
      const res  = await fetch(url);
      const data = await res.json();
      setState({ cityFeed: data.posts || [], cityFeedLoading: false });
    } catch (e) {
      console.error('[DomizioNews] errore fetch città:', e);
      setState({ cityFeedLoading: false });
    }
  }

  // Carica post filtrati per categoria (chip home).
  // GET /wp-json/domizio/v1/posts?category=SLUG&per_page=50
  // Raggruppa per city_slug per alimentare le sezioni home.
  async function loadCategoryFeed(catSlug) {
    if (!catSlug) {
      setState({ activeHomeCat: '', homeCatPosts: {}, homeCatLoading: false });
      return;
    }
    setState({ activeHomeCat: catSlug, homeCatLoading: true });
    try {
      const url  = DOMIZIO_API + '/posts?category=' + encodeURIComponent(catSlug) + '&per_page=50';
      const data = await fetch(url).then(r => r.json()).catch(() => ({ posts: [] }));
      const grouped = {};
      (data.posts || []).forEach(p => {
        const citySlug = p.cities?.[0]?.slug;
        if (citySlug) {
          if (!grouped[citySlug]) grouped[citySlug] = [];
          if (grouped[citySlug].length < 3) grouped[citySlug].push(p);
        }
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
      // 'cellole-baia-domizia' e 'falciano-carinola' richiedono due fetch separate poi merge per data.
      const fetchCityPosts = (slug) => {
        if (slug === 'cellole-baia-domizia') {
          return Promise.all([
            fetch(DOMIZIO_API + '/posts?city=cellole&per_page=5').then(r => r.json()).catch(() => ({ posts: [] })),
            fetch(DOMIZIO_API + '/posts?city=baia-domizia&per_page=5').then(r => r.json()).catch(() => ({ posts: [] })),
          ]).then(([a, b]) => {
            const merged = [...(a.posts || []), ...(b.posts || [])];
            merged.sort((x, y) => new Date(y.date) - new Date(x.date));
            return { posts: merged };
          });
        }
        if (slug === 'falciano-carinola') {
          return Promise.all([
            fetch(DOMIZIO_API + '/posts?city=falciano-del-massico&per_page=5').then(r => r.json()).catch(() => ({ posts: [] })),
            fetch(DOMIZIO_API + '/posts?city=carinola&per_page=5').then(r => r.json()).catch(() => ({ posts: [] })),
          ]).then(([a, b]) => {
            const merged = [...(a.posts || []), ...(b.posts || [])];
            merged.sort((x, y) => new Date(y.date) - new Date(x.date));
            return { posts: merged };
          });
        }
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

      setState({
        posts:         feed.posts || [],
        cities:        config.cities || [],
        categories:    config.categories || [],
        stickyNews:    Array.isArray(sticky) ? sticky : [],
        homeCityPosts: homeCityPosts,
        loading:       false,
      });
    } catch (e) {
      console.error('Errore API:', e);
      setState({ loading: false });
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
    return `
      <div class="dn-ad-card">
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
          <span class="dn-time">${timeAgo(post.date)}</span>
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
          <span class="dn-time">${timeAgo(post.date)}</span>
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

  // ─── HOME: sezioni per città filtrate per categoria ──────────────────────────
  function buildHome() {
    // Search mode: solo header trasformato + risultati ricerca
    if (state.searchMode) {
      return `
        <div class="dn-screen" id="screen-home">
          ${buildHeader()}
          <main>
            <div class="dn-feed" id="dn-search-results">
              <p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>
            </div>
          </main>
        </div>`;
    }

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
        // Per la sezione unificata in homeCatPosts cerchiamo entrambi gli slug
        let cityPosts;
        if (activeCat) {
          if (slug === 'cellole-baia-domizia') {
            const a = state.homeCatPosts['cellole'] || [];
            const b = state.homeCatPosts['baia-domizia'] || [];
            cityPosts = [...a, ...b].sort((x, y) => new Date(y.date) - new Date(x.date));
          } else if (slug === 'falciano-carinola') {
            const a = state.homeCatPosts['falciano-del-massico'] || [];
            const b = state.homeCatPosts['carinola'] || [];
            cityPosts = [...a, ...b].sort((x, y) => new Date(y.date) - new Date(x.date));
          } else {
            cityPosts = state.homeCatPosts[slug] || [];
          }
        } else {
          cityPosts = state.homeCityPosts[slug] || [];
        }
        if (cityPosts.length === 0) return;
        cityCount++;
        if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
          citySections += renderAd('home-feed');
        }
        const shown = cityPosts.slice(0, 3);
        const gotoSlug = CITY_GOTO_TARGET[slug] || slug;
        citySections += `
          <section class="dn-city-section" id="city-section-${slug}">
            <div class="dn-section-label" data-goto-city="${gotoSlug}">${label}</div>
            <div class="dn-feed">
              ${shown.map((p, idx) => {
                const isLast = idx === shown.length - 1;
                return idx === 0 ? buildHeroCard(p, isLast) : buildArticleCard(p, isLast);
              }).join('')}
            </div>
            <div class="dn-city-more-wrap">
              <button class="dn-city-more" data-goto-city="${gotoSlug}"><span class="material-symbols-outlined" style="font-size:18px;">newspaper</span>Vedi altro</button>
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
          <a href="#" data-legal="chi-siamo">Chi Siamo</a>
          <a href="#" data-legal="contatti">Contatti</a>
          <a href="#" data-legal="privacy-policy">Privacy Policy</a>
          <a href="#" data-legal="cookie-policy">Cookie Policy</a>
          <a href="#" data-legal="note-legali">Note Legali</a>
          <a href="#" data-legal="disclaimer">Disclaimer</a>
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
    }

    return `
      <div class="dn-screen">
        ${buildHeader()}
        <div class="dn-detail-header">
          <div style="width:32px;"></div>
          <span style="font-size:16px;font-weight:500;color:var(--color-text);">Città</span>
          <div style="width:32px;"></div>
        </div>
        <main>
          <div class="dn-chips-scroll">
            ${state.cities.map(c => `
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
          <button class="dn-back-btn" id="dn-back">Indietro</button>
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
  const STYLES = `
    @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');

    :root {
      --color-text: #202124;
      --color-text-secondary: #5F6368;
      --color-primary: #6750A4;
      --color-brand: #1C1B1F;
      --color-divider: #E0E0E0;
      --color-background: #FEF7FF;
      --color-card: #FFFFFF;
      --color-chip-inactive-bg: transparent;
      --color-chip-active-bg: #EADDFF;
      --color-chip-active-text: #21005D;
      --color-separator: #E8EAED;
      --elevation-1: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08);
      --elevation-0: none;
      --md-sys-color-primary: #6750A4;
      --md-sys-color-primary-container: #EADDFF;
      --md-sys-color-surface: #FEF7FF;
      --md-sys-color-outline: #79747E;
      --md-sys-color-on-surface: #1C1B1F;
    }

    * { font-family: 'Roboto', Arial, sans-serif; }
    .dn-app { font-family: 'Roboto', Arial, sans-serif; background: var(--color-background); min-height: 100vh; padding-bottom: 64px; }

    /* LOADING */
    .dn-loading { height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #FEF7FF; color: #1C1B1F; gap: 8px; }

    /* SPINNER MD3 */
    .dn-spinner { width: 48px; height: 48px; border: 4px solid #EADDFF; border-top-color: #6750A4; border-radius: 50%; animation: dn-spin 0.8s linear infinite; }
    @keyframes dn-spin { to { transform: rotate(360deg); } }

    /* LOGO */
    .dn-logo { display: flex; align-items: baseline; gap: 3px; pointer-events: none; position: absolute; left: 0; right: 0; justify-content: center; }
    /* pointer-events:auto sui singoli span (non sul container) così il box
       assoluto full-width del logo NON intercetta i click destinati al
       pulsante ricerca/avatar sottostanti — clickable solo sul testo. */
    .dn-logo-domizio { font-family: 'Roboto', Arial, sans-serif; font-weight: 700; font-size: 24px; color: #6750A4; letter-spacing: -0.02em; line-height: 1; pointer-events: auto; }
    .dn-logo-news { font-family: 'Roboto', Arial, sans-serif; font-weight: 300; font-size: 24px; color: #49454F; letter-spacing: -0.02em; line-height: 1; pointer-events: auto; }

    /* TOP HEADER — M3 */
    .dn-top-header { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; background: #FEF7FF; }
    .dn-top-header.dn-search-active { padding: 10px 16px; gap: 12px; background: #FFFFFF; box-shadow: var(--elevation-1); }
    .dn-header-btn { background: none; border: none; cursor: pointer; padding: 4px; display: flex; align-items: center; }
    .dn-header-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--color-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 500; flex-shrink: 0; font-family: 'Roboto', Arial, sans-serif; }
    .dn-search-back-btn { background: none; border: none; cursor: pointer; padding: 4px; display: flex; align-items: center; flex-shrink: 0; }
    #dn-search-input { flex: 1; padding: 10px 14px; border-radius: 24px; border: none; background: #F1F3F4; font-size: 16px; outline: none; font-family: 'Roboto', Arial, sans-serif; color: var(--color-text); width: 100%; box-sizing: border-box; }

    /* PAGE HEADER (tabs secondari) */
    .dn-page-header { padding: 16px 16px 0; }
    .dn-page-header h2 { margin: 0 0 16px; font-size: 20px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; }

    /* CHIP MENU — M3 Filter Chips */
    .dn-home-chips { display: flex; gap: 8px; overflow-x: auto; padding: 10px 16px; background: var(--color-background) !important; border: none !important; border-bottom: none !important; box-shadow: none !important; scrollbar-width: none; -ms-overflow-style: none; position: sticky; top: 0; z-index: 10; }
    .dn-home-chips::-webkit-scrollbar { display: none; }
    .dn-home-chip { flex-shrink: 0; height: 32px !important; padding: 0 12px !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; cursor: pointer; font-size: 14px !important; font-weight: 500 !important; letter-spacing: 0.1px !important; background: transparent !important; color: #444746 !important; transition: background 0.2s, color 0.2s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; display: inline-flex !important; align-items: center !important; }
    .dn-home-chip.active { background: #EADDFF !important; color: #21005D !important; font-weight: 500 !important; }

    /* SLIDER NOTIZIE IN EVIDENZA */
    .dn-slider-wrap { padding: 16px 0 8px; border-bottom: 8px solid var(--color-separator); background: transparent !important; box-shadow: none !important; border-left: none !important; border-right: none !important; border-top: none !important; }
    .dn-slider { display: flex; gap: 12px; overflow-x: auto; padding-left: 16px; padding-right: 4px; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; background: transparent !important; box-shadow: none !important; }
    .dn-slider::-webkit-scrollbar { display: none; }
    .dn-slider-card { flex-shrink: 0; width: calc(75% - 6px); scroll-snap-align: start; cursor: pointer; background: transparent !important; border: none !important; box-shadow: none !important; }
    .dn-slider-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; border-radius: 8px; background: transparent !important; }
    .dn-slider-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .dn-slider-body { padding: 8px 0 0; background: transparent !important; box-shadow: none !important; border: none !important; }
    .dn-slider-title { margin: 6px 0 0; font-size: 22px; font-weight: 700; letter-spacing: 0; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dn-slider-dots { display: flex; gap: 4px; justify-content: center; padding: 10px 0 4px; }
    .dn-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-divider); transition: background 0.2s; flex-shrink: 0; }
    .dn-dot.active { background: var(--color-primary); }
    .dn-vip-badge { font-size: 10px; font-weight: 600; color: #fff; background: var(--color-primary); padding: 2px 7px; border-radius: 4px; letter-spacing: .3px; }

    /* SEZIONI CITTÀ */
    .dn-city-section { background: #FFFFFF; border-radius: 12px; overflow: hidden; margin: 8px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .dn-section-label { font-size: 22px; font-weight: 500; letter-spacing: 0; color: #202124; padding: 16px 16px 8px 16px; display: block; cursor: pointer; background: transparent; border-left: none; text-transform: none; }
    .dn-section-separator { display: none; }

    /* BOTTONE "VEDI ALTRO" */
    .dn-city-more-wrap { padding: 8px 16px 16px; background: transparent; display: flex; justify-content: center; }
    .dn-city-more { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #FFFFFF; border: 1px solid #E0E0E0; border-radius: 50px; cursor: pointer; color: #6750A4; font-size: 14px; font-weight: 500; letter-spacing: 0.1px; font-family: 'Roboto', Arial, sans-serif; margin: 12px 16px; }
    .dn-city-more:active { opacity: 0.7; }

    /* FEED CONTAINER */
    .dn-feed { background: transparent; }

    /* HERO CARD — M3 elevation-1 */
    .dn-card-hero { cursor: pointer; background: var(--color-card); border-radius: 16px; margin: 12px 16px; overflow: hidden; box-shadow: var(--elevation-1); border-bottom: none; }
    .dn-card-hero.dn-card-last { border-bottom: none; }
    .dn-card-hero:active { opacity: 0.85; }
    .dn-card-hero-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; }
    .dn-card-hero-img img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 0; }
    .dn-card-hero-body { padding: 12px 16px 16px; }
    .dn-card-hero-title { margin: 0 0 6px; font-size: 24px; font-weight: 600; letter-spacing: 0; color: var(--color-brand); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; word-break: normal; overflow-wrap: break-word; }

    /* LIST CARDS — M3 outline card */
    .dn-card-list { display: flex; gap: 12px; padding: 16px; border: 1px solid #E0E0E0; border-radius: 12px; margin: 8px 16px; overflow: hidden; background: var(--color-card); cursor: pointer; align-items: flex-start; transition: background 0.1s; }
    .dn-card-list.dn-card-last { border-bottom: 1px solid #E0E0E0; }
    .dn-card-list:active { background: #F1F3F4; }
    .dn-card-list > img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
    .dn-card-body { flex: 1; min-width: 0; }
    .dn-card-body h3 { margin: 0 0 6px; font-size: 16px; font-weight: 400; letter-spacing: 0.5px; color: var(--color-brand); font-family: 'Roboto', Arial, sans-serif; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: normal; overflow-wrap: break-word; }

    /* CARD BADGES (categoria + città) */
    .dn-card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
    .dn-cat-label { font-size: 12px; font-weight: 500; letter-spacing: 0.5px; color: var(--color-primary); background: var(--color-chip-active-bg); padding: 2px 8px; border-radius: 4px; }
    .dn-city-label { font-size: 12px; font-weight: 500; letter-spacing: 0.5px; color: var(--color-text-secondary); background: #E8EAED; padding: 2px 8px; border-radius: 4px; }

    /* TIME */
    .dn-time { font-size: 12px; font-weight: 400; letter-spacing: 0.4px; color: #5F6368; display: block; margin-top: 6px; }

    /* CHIPS (tab Città e Scopri) — M3 Filter Chips */
    .dn-chips-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 10px 16px; background: var(--color-background); border: none; box-shadow: none; scrollbar-width: none; -ms-overflow-style: none; }
    .dn-chips-scroll::-webkit-scrollbar { display: none; }
    .dn-chip { flex-shrink: 0; height: 32px !important; padding: 0 12px !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; cursor: pointer; font-size: 14px !important; font-weight: 500 !important; letter-spacing: 0.1px !important; background: transparent !important; color: #444746 !important; transition: background 0.2s, color 0.2s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; display: inline-flex !important; align-items: center !important; }
    .dn-chip.active { background: #EADDFF !important; color: #21005D !important; font-weight: 500 !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; }

    /* CATEGORY GRID */
    .dn-cat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; padding: 0 16px 20px; }
    .dn-cat-tile { padding: 14px 8px; border-radius: 8px; border: 1px solid var(--color-divider); cursor: pointer; font-size: 13px; font-weight: 500; line-height: 1.3; background: var(--color-card); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; text-align: center; }
    .dn-cat-tile.active { background: var(--color-chip-active-bg); border-color: var(--color-primary); color: var(--color-primary); }

    /* SEARCH (tab Cerca legacy) */
    .dn-search-wrap { position: relative; }

    /* EMPTY */
    .dn-empty { color: var(--color-text-secondary); text-align: center; font-size: 15px; }

    /* ARTICLE DETAIL */
    .dn-detail { min-height: 100vh; background: var(--color-background); padding-bottom: 80px; }
    .dn-detail-header { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,0.97); backdrop-filter: blur(12px); padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--color-divider); }
    .dn-back-btn { background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 15px; font-weight: 500; padding: 0; font-family: 'Roboto', Arial, sans-serif; }
    .dn-share-btn { background: none; border: none; cursor: pointer; color: var(--color-text-secondary); font-size: 14px; font-weight: 500; padding: 0; font-family: 'Roboto', Arial, sans-serif; }
    .dn-detail-img-wrap { position: relative; width: calc(100% - 32px); margin: 16px 16px 0; aspect-ratio: 16/9; overflow: hidden; border-radius: 8px; }
    .dn-detail-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 8px; }

    .dn-photo-credit { font-size: 11px; color: #5F6368; padding: 4px 16px 0; text-align: right; font-family: 'Roboto', Arial, sans-serif; }
    .dn-photo-credit a { color: #5F6368; text-decoration: underline; }
    .dn-detail-body { padding: 0 16px; margin-top: 16px; }
    .dn-badges { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .dn-badge-cat { background: var(--color-primary); color: #fff; font-size: 12px; font-weight: 500; letter-spacing: 0.5px; text-transform: uppercase; padding: 3px 10px; border-radius: 4px; }
    .dn-badge-city { background: var(--color-divider); color: var(--color-text-secondary); font-size: 12px; font-weight: 500; letter-spacing: 0.5px; padding: 3px 10px; border-radius: 4px; }
    .dn-detail-title { margin: 0 0 12px; font-size: 32px; font-weight: 700; letter-spacing: 0; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.2; }
    .dn-detail-byline { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--color-divider); }
    .dn-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--color-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; flex-shrink: 0; }
    .dn-byline-name { font-size: 13px; font-weight: 500; color: var(--color-text-secondary); }
    .dn-byline-date { font-size: 13px; color: var(--color-text-secondary); }
    .dn-detail-content { font-size: 16px; font-weight: 400; letter-spacing: 0.5px; line-height: 1.65; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; }
    .dn-detail-content p { margin: 0 0 16px; line-height: 1.75; }
    .dn-detail-content strong { color: var(--color-text); font-weight: 700; }
    .dn-local-context { background: #EADDFF; border-left: 3px solid var(--color-primary); padding: 12px; border-radius: 0 8px 8px 0; font-size: 14px !important; }

    /* AD CARD SPONSORIZZATA */
    .dn-ad-card { position: relative; padding: 16px; border-top: 8px solid #F2F2F2; border-bottom: 8px solid #F2F2F2; background: var(--color-card); }
    .dn-ad-card img { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; display: block; }
    .dn-ad-badge { position: absolute; top: 24px; left: 24px; font-size: 10px; font-weight: 500; color: #5F6368; background: #F2F2F2; padding: 3px 8px; border-radius: 4px; letter-spacing: .3px; z-index: 1; }

    /* SCOPRI — griglia categorie */
    .dn-scopri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
    .dn-scopri-card { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; border-radius: 8px; overflow: hidden; cursor: pointer; background: #FEF7FF; }
    .dn-scopri-card:active { opacity: 0.85; }
    .dn-scopri-card-name { color: #202124; font-size: 14px; font-weight: 600; line-height: 1.2; text-align: center; font-family: 'Roboto', Arial, sans-serif; }

    /* SCOPRI — header risultati */
    .dn-scopri-header { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,0.97); backdrop-filter: blur(12px); padding: 14px 16px; display: flex; align-items: center; border-bottom: 1px solid var(--color-divider); }
    .dn-scopri-back { background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 15px; font-weight: 500; padding: 0; flex-shrink: 0; width: 72px; text-align: left; font-family: 'Roboto', Arial, sans-serif; }
    .dn-scopri-title { font-size: 16px; font-weight: 700; color: var(--color-text); flex: 1; text-align: center; font-family: 'Roboto', Arial, sans-serif; }

    /* SCOPRI — card attività */
    .dn-card-attivita { background: var(--color-card); border-bottom: 8px solid var(--color-separator); }
    .dn-card-attivita-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; }
    .dn-card-attivita-img img { width: 100%; height: 100%; object-fit: cover; border-radius: 0; display: block; }
    .dn-card-attivita-body { padding: 12px 16px 16px; }
    .dn-badge-attivita { font-size: 11px; font-weight: 500; color: #2E7D32; background: #E8F4EA; padding: 2px 8px; border-radius: 4px; }
    .dn-card-attivita-price { font-size: 12px; font-weight: 500; color: var(--color-text-secondary); margin: 6px 0 2px; }
    .dn-card-attivita-title { font-size: 16px; font-weight: 700; color: var(--color-text); margin: 8px 0 4px; line-height: 1.3; font-family: 'Roboto', Arial, sans-serif; }
    .dn-card-attivita-addr { font-size: 13px; color: var(--color-text-secondary); margin-bottom: 12px; }
    .dn-card-attivita-btns { display: flex; gap: 8px; }
    .dn-btn-action { flex: 1; padding: 8px 4px; border-radius: 8px; border: 1px solid var(--color-divider); background: var(--color-card); font-size: 13px; font-weight: 500; color: var(--color-primary); cursor: pointer; text-align: center; font-family: 'Roboto', Arial, sans-serif; text-decoration: none; display: inline-block; }
    .dn-btn-action:active { background: var(--color-chip-active-bg); }

    /* SCOPRI — card articolo */
    .dn-card-scopri-art { background: var(--color-card); border-bottom: 1px solid var(--color-divider); cursor: pointer; }
    .dn-card-scopri-art:active { opacity: 0.8; }
    .dn-card-scopri-art-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; }
    .dn-card-scopri-art-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .dn-card-scopri-art-body { padding: 12px 16px 16px; }
    .dn-badge-articolo { font-size: 11px; font-weight: 500; color: var(--color-primary); background: var(--color-chip-active-bg); padding: 2px 8px; border-radius: 4px; }
    .dn-card-scopri-art-title { font-size: 16px; font-weight: 700; color: var(--color-text); margin: 8px 0 4px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-family: 'Roboto', Arial, sans-serif; }

    /* BOTTOM NAV */
    .dn-bottom-nav { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 430px; background: var(--color-card); border-top: 1px solid var(--color-divider); display: flex; padding-bottom: env(safe-area-inset-bottom); z-index: 100; }
    .dn-nav-tab { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; padding: 10px 0 6px; gap: 2px; color: #5F6368; transition: color 0.15s; }
    .dn-nav-tab.active { color: #21005D; }
    .dn-nav-icon-wrap { display: flex; align-items: center; justify-content: center; padding: 4px 16px; border-radius: 50px; transition: background 0.15s; }
    .dn-nav-icon-wrap .material-symbols-outlined { font-size: 24px; }
    .dn-nav-icon-wrap.active { background: #EADDFF; }
    .dn-nav-label { font-size: 12px; font-weight: 500; letter-spacing: 0.5px; font-family: 'Roboto', Arial, sans-serif; }

    /* ── FOOTER ─────────────────────────────────────────────── */
    .dn-footer {
      padding: 24px 16px 40px;
      border-top: 1px solid #E8EAED;
      margin-top: 16px;
      text-align: center;
    }
    .dn-footer-links {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 8px 16px;
      margin-bottom: 12px;
    }
    .dn-footer-links a {
      font-size: 12px;
      color: #5F6368;
      text-decoration: none;
    }
    .dn-footer-links a:hover {
      text-decoration: underline;
    }
    .dn-footer-copy {
      font-size: 11px;
      color: #9AA0A6;
      margin: 0;
    }

    /* ── LEGAL PAGE ──────────────────────────────────────────── */
    .dn-legal-page {
      padding-bottom: 80px;
    }
    .dn-legal-content {
      padding: 16px;
      font-size: 14px;
      line-height: 1.7;
      color: #3C4043;
    }
    .dn-legal-content p {
      margin-bottom: 16px;
    }
    .dn-legal-content a {
      color: #6750A4;
    }
  `;

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

    if (state.tab === 'home')       content = buildHome();
    if (state.tab === 'cities')     content = buildCities();
    if (state.tab === 'categories') content = buildScopri();
    if (state.tab === 'search')     content = buildSearch();

    root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${content}${buildFooter()}${!state.loading ? renderAd('banner-nav') : ''}${buildNav()}</div>`;
    // Quando l'input di ricerca compare (header search mode o tab Cerca), dagli focus.
    // La delega globale di 'input' è già wirata una volta sola su root — non serve re-bindare.
    document.getElementById('dn-search-input')?.focus();
    initAds();
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

      // Back button da legal page (usa data-action per distinguere da altre back)
      if (t.closest('[data-action="back-legal"]')) {
        setState({ selectedLegalPage: null });
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
      if (t.closest('#dn-logo-home')) {
        setState({ tab: 'home', selectedPost: null, selectedLegalPage: null, searchMode: false });
        window.scrollTo({ top: 0, behavior: 'smooth' });
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

      // Footer legal links
      const legal = t.closest('[data-legal]');
      if (legal) {
        e.preventDefault();
        setState({ selectedLegalPage: legal.dataset.legal, selectedPost: null });
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

      // Chip categorie (home) — "Tutte" resetta, le altre caricano dal server
      const homeCat = t.closest('[data-home-cat]');
      if (homeCat) {
        const slug = homeCat.dataset.homeCat;
        if (slug === state.activeHomeCat) return; // stesso chip: nessuna azione
        if (!slug) {
          // "Tutte": ripristina le sezioni per città caricate al boot
          setState({ activeHomeCat: '', homeCatPosts: {}, homeCatLoading: false });
        } else {
          loadCategoryFeed(slug);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // Section label città cliccabile + "Vedi altro" → tab Città
      // (Il precedente attachEvents bindava due volte [data-goto-city] — qui una sola,
      // con lo scrollTo ereditato dal ramo .dn-section-label[data-goto-city].)
      const gotoCity = t.closest('[data-goto-city]');
      if (gotoCity) {
        const slug = gotoCity.dataset.gotoCity;
        setState({ tab: 'cities', selectedCity: slug, cityFeed: [], cityFeedLoading: true });
        loadCityFeed(slug);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      // City chips (tab Città) — fetch server-side per slug corretto
      const cityChip = t.closest('[data-city]');
      if (cityChip) {
        const slug    = cityChip.dataset.city;
        const newSlug = state.selectedCity === slug ? '' : slug;
        setState({ selectedCity: newSlug, cityFeed: [], cityFeedLoading: !!newSlug });
        loadCityFeed(newSlug);
        return;
      }

      // Category tiles (tab Scopri legacy — non più usato, mantenuto per sicurezza)
      const catChip = t.closest('[data-cat]');
      if (catChip) {
        const slug = catChip.dataset.cat;
        setState({ selectedCat: state.selectedCat === slug ? '' : slug });
        return;
      }

      // Bottom nav
      const tabEl = t.closest('[data-tab]');
      if (tabEl) {
        setState({ tab: tabEl.dataset.tab, selectedLegalPage: null });
        return;
      }

      // Article cards (feed principale, city feed, slider, ricerca, sticky)
      // IMPORTANTE: data-post-id va per ULTIMO perché più generico — alcuni
      // wrapper esterni (es. section label) potrebbero non essere post ma
      // contenere card annidate con data-post-id più in profondità.
      const postCard = t.closest('[data-post-id]');
      if (postCard) {
        const id   = postCard.dataset.postId;
        // Cerca nel feed principale e nel city feed (post non presenti nei 20 iniziali)
        const post = state.posts.find(p => p.id == id)
                  || state.cityFeed.find(p => p.id == id)
                  || Object.values(state.homeCityPosts).flat().find(p => p.id == id)
                  || Object.values(state.homeCatPosts).flat().find(p => p.id == id)
                  || state.scopriResults.find(p => p.type === 'articolo' && p.id == id)
                  || searchResults.find(p => p.id == id);
        if (post) {
          setState({ selectedPost: post });
          if (post.permalink) {
            history.pushState({ postId: post.id }, post.title, post.permalink);
          }
        } else if (postCard.dataset.stickyHref) {
          // Post sticky non nel feed locale: apri permalink
          window.location.href = postCard.dataset.stickyHref;
        }
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
    window.onpopstate = (e) => {
      if (!e.state || !e.state.postId) {
        setState({ selectedPost: null });
        history.replaceState(null, '', '/');
      }
    };
  }

  // ─── BOOT ───────────────────────────────────────────────────────────────────
  function boot() {
    render();
    setupGlobalDelegation();
    loadData().then(() => {
      // Check if we landed on a pretty permalink (e.g. /titolo-articolo/)
      const path = window.location.pathname;
      if (path && path !== '/' && !path.startsWith('/wp-')) {
        const slug = path.replace(/^\/|\/$/g, ''); // strip leading/trailing slashes
        if (slug) {
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
