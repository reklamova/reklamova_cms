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

## Tryb pracy Codex/Reklamova

Przed kazda zmiana w CMS nalezy pobrac aktualny stan repozytorium:

```bash
git pull --ff-only
```

Dopiero potem:

```bash
git add .
git commit -m "Opis zmiany"
git push
```

Zasada: kod wdrazany na strony klientow powinien pochodzic z aktualnego `main`
albo z oznaczonego taga release. Nie nalezy wdrazac lokalnych, niecommitowanych
zmian.
