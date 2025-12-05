# Návrhy na optimalizaci šablony „Ajax"

## Výkon a assety
- **Snížit blokování vykreslení externími knihovnami:** Zvážit self-hosting nebo doplnění `preload`, `rel="modulepreload"`/`defer` a SRI pro Bootstrap CSS/JS a Bootstrap Icons načítané z CDN, aby se snížil vliv na prvotní vykreslení a zlepšila bezpečnost. Zdroj: `front/layout.twig` řádky 14–16 a 115.
- **Přesunout inline styly do verzovaného CSS:** Stylování hlavičky a obecného layoutu je definované inline v `<style>` bloku. Přesun do verzované/komprimované CSS pipeline umožní cache, minifikaci a jednodušší údržbu. Zdroj: `front/layout.twig` řádky 23–38.
- **Oddělit inline JavaScript pro komentáře:** Skript pro odesílání komentářů je vložen přímo v šabloně. Přesunutí do sdíleného JS bundle (s defer/ESM) usnadní minifikaci, cache a testování. Zdroj: `front/content/detail.twig` řádky 165–249.

## Obrázky
- **Lazy‑loading a rozměry pro obrázky:** Logo a náhled článku nemají `loading="lazy"` ani deklarované rozměry, což může způsobovat layout shift. Doplnit `width`/`height` a lazy‑loading (kromě nad-the-fold loga) nebo `decoding="async"`. Zdroj: `front/layout.twig` řádky 56–58 a `front/content/detail.twig` řádky 35–38.
- **Responsivní varianta náhledů:** Detail článku používá jedno rozlišení obrázku z `thumbnail`. Přidání `srcset/sizes` nebo WebP fallbacku zlepšuje kvalitu i datovou náročnost na různých zařízeních. Zdroj: `front/content/detail.twig` řádky 35–38.

## SEO a metadata
- **Otevřené grafy a canonical tagy:** Stránky používají pouze `<meta name="description">` a strukturovaná data. Doplnění Open Graph/Twitter karet a `<link rel="canonical">` zvýší sdílení a zabrání duplicitám. Zdroj: `front/layout.twig` řádky 4–22 a `front/content/detail.twig` řádky 7–27.
- **Lepší titulky a titulní texty:** Homepage má statický `<title>` a texty; lze doplnit dynamické hodnoty z konfigurace pro lepší SEO. Zdroj: `front/home.twig` řádky 3–9.

## UX a přístupnost
- **Formátované vyhledávání a klávesnice:** Navigační vyhledávání používá standardní formulář bez `aria` popisků pro ikonové tlačítko a nemá prevence prázdných dotazů. Doplnění popisků a jednoduché validace může zvýšit použitelnost. Zdroj: `front/layout.twig` řádky 85–90.
- **Přístupnost komentářů a seznamů:** Komentáře jsou odsazeny pomocí inline `style` a `<div>` struktur; pro čtečky by pomohlo využít seznamy (`<ol>`/`<ul>`), role a ARIA popisky tlačítek „Odpovědět“. Zdroj: `front/content/detail.twig` řádky 79–158.

## Komponentizace
- **Znovupoužitelné partials pro karty příspěvků:** Úvodní stránka a vyhledávání obsahují duplicitu markupů pro karty příspěvků. Extrakce do Twig partialu sníží náklady na údržbu a umožní sdílené změny (třeba lazy‑loading náhledů) na jednom místě. Zdroj: `front/home.twig` řádky 14–39 a `front/search.twig` řádky 23–48.
