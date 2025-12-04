# Blue theme helpers

## Chráněný obsah
Pro obsah viditelný jen přihlášeným uživatelům je k dispozici Twig funkce `protected_content`:

```twig
{{ protected_content('<p>Tajný článek</p>', '<a href="/login">Přihlaste se</a>')|raw }}
```

Pokud není návštěvník přihlášený, zobrazí se fallback (výchozí je krátký text s odkazem na přihlášení). Bez druhého parametru se zobrazí výchozí výzva k přihlášení.
