# Domizio News App — Tema WordPress

Tema mobile-first per testare la web app di Domizio News direttamente su WordPress,
prima di impacchettarla con Capacitor per App Store e Play Store.

## Installazione

1. **Copia la cartella** `domizionews-theme` in `wp-content/themes/`
2. Vai su **Aspetto → Temi** nel pannello WordPress
3. Attiva **Domizio News App**
4. Visita il sito — l'app si carica automaticamente!

## Come funziona

Il tema non usa template classici. Carica un unico `<div id="domizionews-root">` e poi
inietta la SPA JavaScript che legge i dati via REST API da due endpoint custom:

| Endpoint | Descrizione |
|---|---|
| `GET /wp-json/dnapp/v1/feed` | Post con immagine, categorie, città |
| `GET /wp-json/dnapp/v1/config` | Lista città e categorie |

### Parametri del feed

```
/wp-json/dnapp/v1/feed?per_page=20&page=1&city=mondragone&category=cronaca&search=parola
```

## Struttura file

```
domizionews-theme/
├── style.css           ← Intestazione tema WordPress
├── index.php           ← Template principale (solo #root div)
├── header.php          ← <head> con meta mobile
├── footer.php          ← chiusura HTML
├── functions.php       ← Enqueue JS, endpoint REST API, CORS
├── assets/
│   ├── css/base.css    ← Reset CSS mobile
│   └── js/app.js       ← SPA JavaScript vanilla (no build!)
└── README.md
```

## Prossimo passo: pubblicare su App Store e Play Store

Quando sei soddisfatto del risultato su WordPress:

```bash
# 1. Crea progetto Vite + React
npm create vite@latest domizionews-mobile -- --template react
cd domizionews-mobile

# 2. Incolla il file domizionews-app.jsx come src/App.jsx
# 3. Installa Capacitor
npm install @capacitor/core @capacitor/cli @capacitor/ios @capacitor/android

# 4. Inizializza
npx cap init "Domizio News" "it.domizionews.app" --web-dir dist

# 5. Build e sync
npm run build
npx cap add ios
npx cap add android
npx cap sync

# 6. Apri con IDE nativi
npx cap open ios      # → Xcode
npx cap open android  # → Android Studio
```
