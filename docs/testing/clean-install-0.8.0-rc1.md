# Clean install Reklamova CMS 0.8.0-rc1

Data raportu: 2026-07-28

Status: ZABLOKOWANY INFRASTRUKTURALNIE

## Środowisko

Planowany adres: `cms-rc1.reklamova.pl`

Commit: `403534fd0afd1d4261415a0f7446321085f59b03`

Kanał: `rc`

W chwili sporządzenia raportu subdomena nie ma rekordu DNS, nie istnieje
oddzielna baza, a dostępny użytkownik MySQL na hostingu Reklamova ma
uprawnienia tylko do istniejącej bazy produkcyjnej CMS. Testu nie uruchomiono,
ponieważ użycie tej bazy naruszałoby izolację.

## Kontrole wykonane przed stagingiem

| Kontrola | Wynik |
| --- | --- |
| PHP lint na PHP 8.3.31 | ZALICZONA: 114 plików, 0 błędów |
| Walidacja `module.json` | ZALICZONA: 13 plików |
| Walidacja allowlisty core | ZALICZONA |
| Odrzucenie `app/modules/custom/mero` | ZALICZONE |
| Migracja `000008` na wariantach syntetycznych | ZALICZONA |
| Pełna instalacja przez WWW | NIEURUCHOMIONA |

## Scenariusz do wykonania

- [ ] Utworzyć subdomenę i pusty document root.
- [ ] Utworzyć pustą bazę i użytkownika tylko dla tej bazy.
- [ ] Włączyć Basic Auth, `noindex` i blokadę maili.
- [ ] Wgrać świeże pliki z commita RC1.
- [ ] Uruchomić instalator od zera.
- [ ] Potwierdzić wykonanie pełnego ciągu migracji.
- [ ] Zalogować pierwszego administratora.
- [ ] Utworzyć lub nadać rolę `super_admin`.
- [ ] Utworzyć użytkownika `client_admin`.
- [ ] Sprawdzić menu `client_admin`.
- [ ] Sprawdzić menu `reklamova_admin`.
- [ ] Dodać i opublikować podstronę.
- [ ] Wgrać plik do Media i użyć go na stronie.
- [ ] Sprawdzić Privacy Center oraz zapis zgody.
- [ ] Sprawdzić listę i widoczność modułów.
- [ ] Sprawdzić ekran aktualizacji na kanale `rc`.
- [ ] Sprawdzić log PHP i log aplikacji.

## Kryteria zaliczenia

- instalator nie pokazuje warningów ani deprecated,
- wszystkie migracje kończą się jednokrotnie i są idempotentne,
- `client_admin` nie widzi technikaliów,
- `reklamova_admin` i `super_admin` widzą sekcję techniczną,
- zapis strony, upload i Privacy Center działają,
- updater rozpoznaje kanał `rc`,
- logi nie zawierają błędów PHP.

## Wynik

NIEURUCHOMIONY. Do odblokowania potrzebne są subdomena, oddzielny document
root, oddzielna baza i potwierdzona blokada poczty wychodzącej.
