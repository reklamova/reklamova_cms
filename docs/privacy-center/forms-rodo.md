# Formularze i RODO

Panel: `/admin/privacy/forms`.

Modul przechowuje wersjonowane klauzule dla formularzy:

- kontakt
- newsletter
- zapytanie ofertowe
- sklep/zamowienia

## Zasady

- Obowiazek informacyjny i zgoda marketingowa musza byc oddzielne.
- Checkbox marketingowy nie moze byc domyslnie zaznaczony.
- Formularz powinien zapisywac wersje klauzuli obowiazujaca w chwili wyslania.
- Tresc klauzul jest edytowalna przez panel i wersjonowana polem `version`.

## Integracja w formularzu custom

Modul udostepnia `FormConsentService::activeClause($type)`. Modul formularza klienta powinien:

1. pobrac aktywna klauzule dla typu formularza,
2. wyswietlic jej tresc pod formularzem,
3. zapisac `clause_id`, `clause_version` i stan oddzielnych zgody marketingowych w payloadzie formularza.

## TODO po MVP

- Centralna tabela logow zgod formularzowych, jezeli nie zostanie dodana w module forms.
- UI przypisania klauzuli do konkretnego formularza po wdrozeniu pelnego kreatora formularzy CMS.
