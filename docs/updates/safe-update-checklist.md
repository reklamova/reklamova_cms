# Checklista bezpiecznej aktualizacji

Przed release:
- zwiększ wersję w `app/core/Version.php` i `reklamova.json`,
- sprawdź migracje core i modułów,
- upewnij się, że migracje są defensywne,
- sprawdź protected paths,
- zbuduj paczkę ZIP tylko z core paths,
- podpisz paczkę kluczem release,
- zaktualizuj indeks update servera,
- wykonaj test na instalacji testowej.

Test dry run:
- pobiera paczkę,
- weryfikuje checksum i podpis,
- sprawdza wymagania,
- sprawdza prawa zapisu,
- nie zmienia plików.

Test realny:
- robi backup DB,
- robi backup core,
- nie dotyka `app/config`,
- nie dotyka `app/themes`,
- nie dotyka `app/modules/custom`,
- nie dotyka `public/uploads`,
- uruchamia migracje,
- zapisuje log,
- wykonuje health check.

Rollback:
- przywraca core,
- przywraca bazę, jeśli migracje zostały wykonane,
- zapisuje błąd w logu,
- pokazuje bezpieczny komunikat.
