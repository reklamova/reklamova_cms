# GitHub Setup

Docelowe repozytorium:

```text
git@github.com:reklamova/reklamova_cms.git
```

## Wymagane do push

Lokalna maszyna albo srodowisko CI musi miec:

- Git,
- klucz SSH dodany do konta/organizacji GitHub,
- dostep push do `reklamova/reklamova_cms`.

## Pierwszy push lokalny

```bash
git init
git branch -M main
git remote add origin git@github.com:reklamova/reklamova_cms.git
git add .
git commit -m "Initial Reklamova CMS architecture"
git push -u origin main
```

