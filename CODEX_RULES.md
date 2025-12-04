# Codex Rules

- Používej tento soubor pro zapisování pravidel, poznámek a průběžných nápadů týkajících se projektu.
- Každá nová poznámka by měla obsahovat datum nebo kontext, aby bylo zřejmé, kdy vznikla.
- Pokud přidáš nové databázové nebo instalační změny, připiš krátkou připomínku, že byla upravena i instalační logika.
- [2024-05] Přidána tabulka content_media a úpravy instalační logiky kvůli navázání médií na obsah a thumbnail.
- [2024-06] Odstraněny runtime migrace (ALTER TABLE) z kontrolerů a služeb, schéma je kompletně řízené instalací.
- [2024-07] Doplněny instalační migrace pro nové sloupce médií (webp_filename, original_name, alt).
- [2024-08] Přidána tabulka log pro zaznamenávání bezpečnostních událostí (reCAPTCHA) a rozšířen instalátor.
