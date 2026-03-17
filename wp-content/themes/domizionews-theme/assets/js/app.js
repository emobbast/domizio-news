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

  // ─── CLEAN TITLE: rimuove prefisso nome città ────────────────────────────────
  const CITY_NAMES_FOR_CLEAN = [
    'Mondragone', 'Castel Volturno', 'Baia Domizia', 'Cellole',
    'Falciano', 'Carinola', 'Sessa Aurunca', 'Pinetamare', 'Villaggio Coppola',
  ];
  function cleanTitle(title) {
    let t = title || '';
    CITY_NAMES_FOR_CLEAN.forEach(city => {
      const regex = new RegExp('^' + city.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[\\s]*[–—,:]+[\\s]*', 'i');
      t = t.replace(regex, '');
    });
    return t.trim();
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

  function setState(patch) {
    state = Object.assign({}, state, patch);
    render();
  }

  // ─── API ────────────────────────────────────────────────────────────────────

  // Slug esatti registrati nel database — usati sia per la home che per il tab Città
  // 'cellole-baia-domizia' è uno slug virtuale: carica entrambe le città
  const CITY_SLUGS = [
    'mondragone',
    'castel-volturno',
    'cellole-baia-domizia',   // sezione unificata Cellole + Baia Domizia
    'falciano-del-massico',
    'carinola',
    'sessa-aurunca',
  ];

  // Nomi visualizzati per slug (incluso quello virtuale)
  const CITY_SLUG_LABELS = {
    'mondragone':           'Mondragone',
    'castel-volturno':      'Castel Volturno',
    'cellole-baia-domizia': 'Cellole e Baia Domizia',
    'falciano-del-massico': 'Falciano del Massico',
    'carinola':             'Carinola',
    'sessa-aurunca':        'Sessa Aurunca',
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
      // 'cellole-baia-domizia' richiede due fetch separate poi merge per data.
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
  function renderAdSlot(index) {
    const ads = [
      { img: 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=800', alt: 'Ristorante sul mare' },
      { img: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=800', alt: 'Lido balneare' },
      { img: 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=800', alt: 'Offerta ristorante' },
      { img: 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=800', alt: 'Negozio locale' }
    ];
    const ad = ads[index % ads.length];
    return `
      <div style="padding:16px;border-top:8px solid #F2F2F2;border-bottom:8px solid #F2F2F2;position:relative;">
        <span style="position:absolute;top:20px;left:20px;background:#F2F2F2;color:#5F6368;font-size:11px;padding:2px 6px;border-radius:4px;z-index:1;">Sponsorizzato</span>
        <img src="${escHtml(ad.img)}" alt="${escHtml(ad.alt)}" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:8px;display:block;" />
      </div>
    `;
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

  // Hero card: immagine full-width 16/9
  // isLast = true → nessun border-bottom (evita doppio bordo con separatore sezione)
  function buildHeroCard(post, isLast) {
    const img = post.image || '';
    return `
      <div class="dn-card-hero${isLast ? ' dn-card-last' : ''}" data-post-id="${post.id}">
        ${img ? `<div class="dn-card-hero-img"><img src="${img}" alt="" loading="lazy"></div>` : ''}
        <div class="dn-card-hero-body">
          ${buildCardBadges(post)}
          <h3 class="dn-card-hero-title">${escHtml(decodeHtml(cleanTitle(post.title)))}</h3>
          <span class="dn-time">${timeAgo(post.date)}</span>
        </div>
      </div>`;
  }

  // List card: thumbnail 80x80 a destra
  // isLast = true → nessun border-bottom
  function buildArticleCard(post, isLast) {
    const img = post.image || '';
    return `
      <div class="dn-card-list${isLast ? ' dn-card-last' : ''}" data-post-id="${post.id}">
        <div class="dn-card-body">
          ${buildCardBadges(post)}
          <h3>${escHtml(decodeHtml(cleanTitle(post.title)))}</h3>
          <span class="dn-time">${timeAgo(post.date)}</span>
        </div>
        ${img ? `<img src="${img}" alt="" loading="lazy">` : ''}
      </div>`;
  }

  // ─── SEARCH: debounce timer (modulo-level, sopravvive ai re-render) ──────────
  let searchDebounceTimer = null;

  // ─── SCOPRI: costanti ────────────────────────────────────────────────────────
  const SCOPRI_CATEGORIES = [
    { nome: 'Ristoranti & Locali',    slug: 'ristoranti-locali',    img: 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=800' },
    { nome: 'Eventi & Concerti',      slug: 'eventi-concerti',      img: 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=800' },
    { nome: 'Spiagge & Stabilimenti', slug: 'spiagge-stabilimenti', img: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=800' },
    { nome: 'Immobiliare',            slug: 'immobiliare',          img: 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=800' },
    { nome: 'Negozi',                 slug: 'negozi',               img: 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=800' },
    { nome: 'Food & Gusto',           slug: 'food-gusto',           img: 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=800' },
    { nome: 'Turismo & Vacanze',      slug: 'turismo-vacanze',      img: 'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800' },
    { nome: 'Shopping',               slug: 'shopping',             img: 'https://images.unsplash.com/photo-1483985988355-763728e1935b?w=800' },
    { nome: 'Benessere',              slug: 'benessere',            img: 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?w=800' },
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
        <div class="dn-top-header dn-search-active">
          <button class="dn-search-back-btn" id="dn-search-back" aria-label="Indietro">
            <span class="material-symbols-outlined" style="font-size:24px;color:#202124;">arrow_back</span>
          </button>
          <input id="dn-search-input" type="search" placeholder="Cerca argomenti, località e fonti" autocomplete="off">
        </div>`;
    }
    return `
      <div class="dn-top-header">
        <button class="dn-header-btn" id="dn-header-search" aria-label="Cerca">
          <span class="material-symbols-outlined" style="font-size:24px;color:#FFFFFF;">search</span>
        </button>
        <h1 class="dn-site-title">Domizio News</h1>
        <div class="dn-header-avatar">D</div>
      </div>`;
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
          permalink: p.link || '',
          is_vip:    !!p.sticky,
        }));

    if (items.length === 0) return '';

    return `
      <div class="dn-slider-wrap">
        <div class="dn-slider" id="dn-slider">
          ${items.map(item => `
            <div class="dn-slider-card" data-sticky-href="${item.permalink}" data-post-id="${item.post_id}">
              ${item.image ? `<div class="dn-slider-img"><img src="${item.image}" alt="" loading="lazy"></div>` : ''}
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
          ${items.map((_, i) => `<span class="dn-dot ${i === 0 ? 'active' : ''}"></span>`).join('')}
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
          <div class="dn-feed" id="dn-search-results">
            <p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>
          </div>
        </div>`;
    }

    const activeCat = state.activeHomeCat; // '' = Tutte

    let citySections = '';
    if (state.homeCatLoading) {
      citySections = `<p class="dn-empty" style="padding:40px 16px">Caricamento...</p>`;
    } else {
      let cityCount = 0;
      let adIdx = 0;
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
          } else {
            cityPosts = state.homeCatPosts[slug] || [];
          }
        } else {
          cityPosts = state.homeCityPosts[slug] || [];
        }
        if (cityPosts.length === 0) return;
        cityCount++;
        if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
          citySections += renderAdSlot(adIdx++);
        }
        const shown = cityPosts.slice(0, 3);
        citySections += `
          <div class="dn-city-section" id="city-section-${slug}">
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
          </div>
          <div class="dn-section-separator"></div>`;
      });
      if (cityCount === 0) {
        citySections = `<p class="dn-empty" style="padding:40px 16px">Nessuna notizia per questa categoria.</p>`;
      }
    }

    return `
      <div class="dn-screen" id="screen-home">
        ${buildHeader()}
        ${buildCategoryChipsBar()}
        ${buildSlider()}
        ${citySections}
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
        <p class="dn-footer-copy">© 2025 Domizio News</p>
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
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Caricamento...</p>`;
    } else if (state.cityFeed.length === 0) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Nessun articolo per questa città.</p>`;
    } else {
      feedHtml = state.cityFeed.map(p => buildArticleCard(p)).join('');
    }

    return `
      <div class="dn-screen">
        <div style="padding:12px 16px;border-bottom:1px solid #E0E0E0;display:flex;align-items:center;gap:8px;background:#fff;">
          <span class="material-symbols-outlined" style="font-size:20px;color:#202124;cursor:pointer;" id="btn-back-home">arrow_back</span>
          <span style="font-size:16px;font-weight:500;color:#202124;">Città</span>
        </div>
        <div class="dn-chips-scroll">
          ${state.cities.map(c => `
            <button class="dn-chip ${state.selectedCity === c.slug ? 'active' : ''}" data-city="${escHtml(c.slug)}">${escHtml(c.name)}</button>
          `).join('')}
        </div>
        <div class="dn-feed">
          ${feedHtml}
        </div>
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
          <div class="dn-top-header">
            <h1 class="dn-site-title">Scopri</h1>
          </div>
          <div class="dn-scopri-grid">
            ${SCOPRI_CATEGORIES.map(c => `
              <div class="dn-scopri-card" data-scopri-cat="${c.slug}">
                <img class="dn-scopri-card-img" src="${c.img}" alt="" loading="lazy">
                <div class="dn-scopri-card-overlay"></div>
                <div class="dn-scopri-card-name">${c.nome}</div>
              </div>
            `).join('')}
          </div>
        </div>`;
    }

    // STEP 2 — risultati
    const cat = SCOPRI_CATEGORIES.find(c => c.slug === state.scopriCategoria);
    const catNome = cat ? cat.nome : state.scopriCategoria;

    let feedHtml;
    if (state.scopriLoading) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Caricamento...</p>`;
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
        <div class="dn-scopri-header">
          <button class="dn-scopri-back" data-scopri-back>← Indietro</button>
          <span class="dn-scopri-title">${catNome}</span>
          <span style="width:72px"></span>
        </div>
        <div class="dn-chips-scroll" style="padding-top:12px">
          ${SCOPRI_CITIES.map(c => `
            <button class="dn-chip ${state.scopriCity === c.slug ? 'active' : ''}" data-scopri-city="${c.slug}">${c.name}</button>
          `).join('')}
        </div>
        <div class="dn-feed">${feedHtml}</div>
      </div>`;
  }

  function buildSearch() {
    // L'input è NON controllato: non ha l'attributo value e non viene mai
    // ricreato durante la digitazione. I risultati vengono aggiornati da
    // attachEvents() tramite patch diretta del DOM con debounce 300ms,
    // evitando così il reset del cursore su mobile ad ogni carattere.
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

  function renderSearchResults(q) {
    const resultsEl = document.getElementById('dn-search-results');
    if (!resultsEl) return;
    const filtered = q.length > 1
      ? state.posts.filter(p =>
          p.title.toLowerCase().includes(q.toLowerCase()) ||
          (p.excerpt || '').toLowerCase().includes(q.toLowerCase()))
      : [];
    resultsEl.innerHTML = q.length < 2
      ? `<p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>`
      : filtered.length === 0
        ? `<p class="dn-empty" style="padding:60px 16px 0">Nessun risultato per "<b>${escHtml(q)}</b>"</p>`
        : `<p style="font-size:13px;color:#5F6368;padding:0 16px 8px">${filtered.length} risultati</p>
           ${filtered.map(p => buildArticleCard(p)).join('')}`;
    // Ri-aggancia i click handler sulle card appena inserite
    resultsEl.querySelectorAll('[data-post-id]').forEach(el => {
      el.addEventListener('click', () => {
        const post = state.posts.find(p => p.id == el.dataset.postId);
        if (post) setState({ selectedPost: post });
      });
    });
  }

  function buildArticleDetail(post) {
    const date = new Date(post.date).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
    return `
      <div class="dn-detail">
        <div class="dn-detail-header">
          <button class="dn-back-btn" id="dn-back">Indietro</button>
          <button class="dn-share-btn" id="dn-share">Condividi</button>
        </div>
        ${post.image ? `
          <div class="dn-detail-img-wrap">
            <img src="${post.image}" alt="">
            <div class="dn-detail-img-fade"></div>
          </div>` : ''}
        <div class="dn-detail-body">
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
          ${renderAdSlot(0)}
        </div>
      </div>`;
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
        <h2>Domizio News</h2>
        <p>Caricamento notizie...</p>
      </div>`;
  }

  // ─── STYLES ─────────────────────────────────────────────────────────────────
  const STYLES = `
    @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');

    :root {
      --color-text: #202124;
      --color-text-secondary: #5F6368;
      --color-primary: #1A73E8;
      --color-brand: #1a1a2e;
      --color-divider: #E0E0E0;
      --color-background: #F2F2F7;
      --color-card: #FFFFFF;
      --color-chip-inactive-bg: transparent;
      --color-chip-active-bg: #D3E3FD;
      --color-chip-active-text: #001D35;
      --color-separator: #E8EAED;
      --elevation-1: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08);
      --elevation-0: none;
    }

    * { font-family: 'Roboto', Arial, sans-serif; }
    .dn-app { font-family: 'Roboto', Arial, sans-serif; background: var(--color-background); min-height: 100vh; padding-bottom: 64px; }

    /* LOADING */
    .dn-loading { height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--color-text); color: #fff; gap: 8px; }
    .dn-loading h2 { font-family: 'Roboto', Arial, sans-serif; font-weight: 700; font-size: 26px; margin: 0; }
    .dn-loading p { color: var(--color-primary); font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin: 0; }

    /* TOP HEADER — M3 */
    .dn-top-header { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; background: var(--color-brand); }
    .dn-top-header.dn-search-active { padding: 10px 16px; gap: 12px; background: #FFFFFF; box-shadow: var(--elevation-1); }
    .dn-site-title { margin: 0; font-size: 20px; font-weight: 500; color: #FFFFFF; font-family: 'Roboto', Arial, sans-serif; line-height: 1; }
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
    .dn-home-chip { flex-shrink: 0; height: 32px !important; padding: 0 12px !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; cursor: pointer; font-size: 13px !important; font-weight: 400 !important; background: transparent !important; color: #444746 !important; transition: background 0.2s, color 0.2s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; display: inline-flex !important; align-items: center !important; }
    .dn-home-chip.active { background: #D3E3FD !important; color: #001D35 !important; font-weight: 500 !important; }

    /* SLIDER NOTIZIE IN EVIDENZA */
    .dn-slider-wrap { padding: 16px 0 8px; border-bottom: 8px solid var(--color-separator); background: transparent !important; box-shadow: none !important; border-left: none !important; border-right: none !important; border-top: none !important; }
    .dn-slider { display: flex; gap: 12px; overflow-x: auto; padding-left: 16px; padding-right: 4px; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; background: transparent !important; box-shadow: none !important; }
    .dn-slider::-webkit-scrollbar { display: none; }
    .dn-slider-card { flex-shrink: 0; width: calc(75% - 6px); scroll-snap-align: start; cursor: pointer; background: transparent !important; border: none !important; box-shadow: none !important; }
    .dn-slider-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; border-radius: 8px; background: transparent !important; }
    .dn-slider-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .dn-slider-body { padding: 8px 0 0; background: transparent !important; box-shadow: none !important; border: none !important; }
    .dn-slider-title { margin: 6px 0 0; font-size: 16px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dn-slider-dots { display: flex; gap: 4px; justify-content: center; padding: 10px 0 4px; }
    .dn-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-divider); transition: background 0.2s; flex-shrink: 0; }
    .dn-dot.active { background: var(--color-primary); }
    .dn-vip-badge { font-size: 10px; font-weight: 600; color: #fff; background: var(--color-primary); padding: 2px 7px; border-radius: 4px; letter-spacing: .3px; }

    /* SEZIONI CITTÀ */
    .dn-city-section { background: #FFFFFF; border-radius: 12px; overflow: hidden; margin: 8px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .dn-section-label { font-size: 16px; font-weight: 500; color: #202124; letter-spacing: 0.15px; padding: 16px 16px 8px 16px; display: block; cursor: pointer; background: transparent; border-left: none; text-transform: none; }
    .dn-section-separator { display: none; }

    /* BOTTONE "VEDI ALTRO" */
    .dn-city-more-wrap { padding: 8px 16px 16px; background: transparent; display: flex; justify-content: center; }
    .dn-city-more { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #FFFFFF; border: 1px solid #E0E0E0; border-radius: 50px; cursor: pointer; color: #1A73E8; font-size: 14px; font-weight: 500; font-family: 'Roboto', Arial, sans-serif; margin: 12px 16px; }
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
    .dn-card-hero-title { margin: 0 0 6px; font-size: 20px; font-weight: 500; color: var(--color-brand); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; word-break: normal; overflow-wrap: break-word; }

    /* LIST CARDS — M3 outline card */
    .dn-card-list { display: flex; gap: 12px; padding: 16px; border: 1px solid #E0E0E0; border-radius: 12px; margin: 8px 16px; overflow: hidden; background: var(--color-card); cursor: pointer; align-items: flex-start; transition: background 0.1s; }
    .dn-card-list.dn-card-last { border-bottom: 1px solid #E0E0E0; }
    .dn-card-list:active { background: #F1F3F4; }
    .dn-card-list > img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
    .dn-card-body { flex: 1; min-width: 0; }
    .dn-card-body h3 { margin: 0 0 6px; font-size: 15px; font-weight: 500; color: var(--color-brand); font-family: 'Roboto', Arial, sans-serif; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: normal; overflow-wrap: break-word; }

    /* CARD BADGES (categoria + città) */
    .dn-card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
    .dn-cat-label { font-size: 11px; font-weight: 500; color: var(--color-primary); background: var(--color-chip-active-bg); padding: 2px 8px; border-radius: 4px; }
    .dn-city-label { font-size: 11px; font-weight: 500; color: var(--color-text-secondary); background: #E8EAED; padding: 2px 8px; border-radius: 4px; }

    /* TIME */
    .dn-time { font-size: 12px; font-weight: 400; color: #5F6368; display: block; margin-top: 6px; }

    /* CHIPS (tab Città e Scopri) — M3 Filter Chips */
    .dn-chips-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 10px 16px; background: var(--color-background); border: none; box-shadow: none; scrollbar-width: none; -ms-overflow-style: none; }
    .dn-chips-scroll::-webkit-scrollbar { display: none; }
    .dn-chip { flex-shrink: 0; height: 32px !important; padding: 0 12px !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; cursor: pointer; font-size: 13px !important; font-weight: 400 !important; background: transparent !important; color: #444746 !important; transition: background 0.2s, color 0.2s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; display: inline-flex !important; align-items: center !important; }
    .dn-chip.active { background: #D3E3FD !important; color: #001D35 !important; font-weight: 500 !important; border-radius: 50px !important; border: none !important; box-shadow: none !important; }

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
    .dn-detail-img-wrap { position: relative; width: 100%; aspect-ratio: 16/9; overflow: hidden; }
    .dn-detail-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 8px; }
    .dn-detail-img-fade { position: absolute; inset: 0; background: linear-gradient(to top, var(--color-background) 0%, transparent 50%); }
    .dn-detail-body { padding: 0 16px; margin-top: -20px; }
    .dn-badges { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .dn-badge-cat { background: var(--color-primary); color: #fff; font-size: 11px; font-weight: 500; text-transform: uppercase; padding: 3px 10px; border-radius: 4px; }
    .dn-badge-city { background: var(--color-divider); color: var(--color-text-secondary); font-size: 12px; padding: 3px 10px; border-radius: 4px; }
    .dn-detail-title { margin: 0 0 12px; font-size: 28px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.2; }
    .dn-detail-byline { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--color-divider); }
    .dn-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--color-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; flex-shrink: 0; }
    .dn-byline-name { font-size: 13px; font-weight: 500; color: var(--color-text-secondary); }
    .dn-byline-date { font-size: 13px; color: var(--color-text-secondary); }
    .dn-detail-content { font-size: 17px; line-height: 1.65; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; }
    .dn-detail-content p { margin: 0 0 16px; }
    .dn-detail-content strong { color: var(--color-text); font-weight: 700; }
    .dn-local-context { background: #E8F0FE; border-left: 3px solid var(--color-primary); padding: 12px; border-radius: 0 8px 8px 0; font-size: 14px !important; }

    /* AD CARD SPONSORIZZATA */
    .dn-ad-card { position: relative; padding: 16px; border-top: 8px solid #F2F2F2; border-bottom: 8px solid #F2F2F2; background: var(--color-card); }
    .dn-ad-card img { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; display: block; }
    .dn-ad-badge { position: absolute; top: 24px; left: 24px; font-size: 10px; font-weight: 500; color: #5F6368; background: #F2F2F2; padding: 3px 8px; border-radius: 4px; letter-spacing: .3px; z-index: 1; }

    /* SCOPRI — griglia categorie */
    .dn-scopri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
    .dn-scopri-card { position: relative; aspect-ratio: 1/1; border-radius: 8px; overflow: hidden; cursor: pointer; }
    .dn-scopri-card:active { opacity: 0.85; }
    .dn-scopri-card-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .dn-scopri-card-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.72) 0%, rgba(0,0,0,0.15) 55%, transparent 100%); }
    .dn-scopri-card-name { position: absolute; bottom: 12px; left: 12px; right: 12px; color: #fff; font-size: 16px; font-weight: 700; line-height: 1.2; font-family: 'Roboto', Arial, sans-serif; }

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
    .dn-nav-tab.active { color: #001D35; }
    .dn-nav-icon-wrap { display: flex; align-items: center; justify-content: center; padding: 4px 16px; border-radius: 50px; transition: background 0.15s; }
    .dn-nav-icon-wrap .material-symbols-outlined { font-size: 24px; }
    .dn-nav-icon-wrap.active { background: #D3E3FD; }
    .dn-nav-label { font-size: 12px; font-weight: 500; font-family: 'Roboto', Arial, sans-serif; }

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
      color: #1A73E8;
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
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app" style="padding-bottom:0">${buildArticleDetail(state.selectedPost)}</div>`;
      document.getElementById('dn-back')?.addEventListener('click', () => setState({ selectedPost: null }));
      return;
    }

    if (state.selectedLegalPage) {
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}${buildLoading()}${buildNav()}</div>`;
      attachEvents();
      buildLegalPage(state.selectedLegalPage).then(html => {
        root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}${html}${buildNav()}</div>`;
        attachEvents();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }).catch(() => {
        root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildHeader()}<div style="padding:32px 16px;text-align:center;color:#5F6368;">Contenuto non disponibile.<br><br><button class="dn-back-btn" data-action="back-legal" style="color:#1A73E8;">← Torna indietro</button></div>${buildNav()}</div>`;
        attachEvents();
      });
      return;
    }

    if (state.tab === 'home')       content = buildHome();
    if (state.tab === 'cities')     content = buildCities();
    if (state.tab === 'categories') content = buildScopri();
    if (state.tab === 'search')     content = buildSearch();

    root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${content}${buildFooter()}${buildNav()}</div>`;
    attachEvents();
  }

  function attachEvents() {
    // Article cards (feed principale, city feed server-side, slider)
    document.querySelectorAll('[data-post-id]').forEach(el => {
      el.addEventListener('click', () => {
        const id   = el.dataset.postId;
        // Cerca nel feed principale e nel city feed (post non presenti nei 20 iniziali)
        const post = state.posts.find(p => p.id == id)
                  || state.cityFeed.find(p => p.id == id)
                  || Object.values(state.homeCityPosts).flat().find(p => p.id == id)
                  || Object.values(state.homeCatPosts).flat().find(p => p.id == id)
                  || state.scopriResults.find(p => p.type === 'articolo' && p.id == id);
        if (post) {
          setState({ selectedPost: post });
        } else if (el.dataset.stickyHref) {
          // Post sticky non nel feed locale: apri permalink
          window.location.href = el.dataset.stickyHref;
        }
      });
    });

    // Header: click icona lente → attiva search mode
    document.getElementById('dn-header-search')?.addEventListener('click', () => {
      setState({ searchMode: true });
    });

    // Header search mode: freccia ← → torna alla vista normale
    document.getElementById('dn-search-back')?.addEventListener('click', () => {
      clearTimeout(searchDebounceTimer);
      setState({ searchMode: false });
    });

    // Section label città cliccabile → tab Città
    document.querySelectorAll('.dn-section-label[data-goto-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.gotoCity;
        setState({ tab: 'cities', selectedCity: slug, cityFeed: [], cityFeedLoading: true });
        loadCityFeed(slug);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Pulsante indietro tab Città → torna alla Home
    document.getElementById('btn-back-home')?.addEventListener('click', () => {
      setState({ tab: 'home' });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Bottom nav
    document.querySelectorAll('[data-tab]').forEach(el => {
      el.addEventListener('click', () => setState({ tab: el.dataset.tab, selectedLegalPage: null }));
    });

    // City chips (tab Città) — fetch server-side per slug corretto
    document.querySelectorAll('[data-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug    = el.dataset.city;
        const newSlug = state.selectedCity === slug ? '' : slug;
        setState({ selectedCity: newSlug, cityFeed: [], cityFeedLoading: !!newSlug });
        loadCityFeed(newSlug);
      });
    });

    // Category tiles (tab Scopri legacy — non più usato, mantenuto per sicurezza)
    document.querySelectorAll('[data-cat]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.cat;
        setState({ selectedCat: state.selectedCat === slug ? '' : slug });
      });
    });

    // Scopri — click su card categoria (step 1 → step 2)
    document.querySelectorAll('[data-scopri-cat]').forEach(el => {
      el.addEventListener('click', () => {
        loadScopriResults(el.dataset.scopriCat, 'tutte');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Scopri — bottone Indietro (step 2 → step 1)
    document.querySelector('[data-scopri-back]')?.addEventListener('click', () => {
      setState({ scopriStep: 'categorie', scopriCategoria: null, scopriResults: [], scopriLoading: false });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Scopri — chip città (step 2)
    document.querySelectorAll('[data-scopri-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.scopriCity;
        if (slug === state.scopriCity) return;
        loadScopriResults(state.scopriCategoria, slug);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Scopri — bottoni Chiama / WhatsApp (card attività)
    document.querySelectorAll('[data-tel]').forEach(el => {
      el.addEventListener('click', () => { window.location.href = 'tel:' + el.dataset.tel; });
    });
    document.querySelectorAll('[data-wa]').forEach(el => {
      el.addEventListener('click', () => { window.open('https://wa.me/' + el.dataset.wa.replace(/\D/g, ''), '_blank'); });
    });

    // Chip categorie (home) — "Tutte" resetta, le altre caricano dal server
    document.querySelectorAll('[data-home-cat]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.homeCat;
        if (slug === state.activeHomeCat) return; // stesso chip: nessuna azione
        if (!slug) {
          // "Tutte": ripristina le sezioni per città caricate al boot
          setState({ activeHomeCat: '', homeCatPosts: {}, homeCatLoading: false });
        } else {
          loadCategoryFeed(slug);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Home city "Vedi altro" links — passa alla tab Città e carica feed server-side
    document.querySelectorAll('[data-goto-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.gotoCity;
        setState({ tab: 'cities', selectedCity: slug, cityFeed: [], cityFeedLoading: true });
        loadCityFeed(slug);
      });
    });

    // Slider — aggiorna dots al scroll
    const slider = document.getElementById('dn-slider');
    const dotsEl = document.getElementById('dn-slider-dots');
    if (slider && dotsEl) {
      slider.addEventListener('scroll', () => {
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
      }, { passive: true });
    }

    // Search input — input NON controllato + debounce 300ms.
    // L'handler NON chiama render(): aggiorna solo #dn-search-results
    // per evitare che il re-render ricrei l'input e resetti il cursore su mobile.
    const searchInput = document.getElementById('dn-search-input');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
          renderSearchResults(searchInput.value);
        }, 300);
      });
      searchInput.focus();
    }

    // Footer legal links
    document.querySelectorAll('[data-legal]').forEach(el => {
      el.addEventListener('click', e => {
        e.preventDefault();
        setState({ selectedLegalPage: el.dataset.legal, selectedPost: null });
      });
    });

    // Back button from legal page
    document.querySelectorAll('[data-action="back-legal"]').forEach(el => {
      el.addEventListener('click', () => {
        setState({ selectedLegalPage: null });
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  }

  // ─── BOOT ───────────────────────────────────────────────────────────────────
  function boot() {
    render(); // mostra loading
    loadData();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();
