# Checklist testowa Privacy Center

## 1. Nowy uzytkownik

1. Otworz strone w incognito.
2. Wyczyść localStorage i cookies, jesli testujesz ponownie.
3. Sprawdz, ze baner jest widoczny.
4. Sprawdz w Network, ze GA4, GTM, Meta Pixel, Clarity, Hotjar i inne skrypty nie zostaly zaladowane przed decyzja.
5. W konsoli sprawdz `window.ReklamovaConsentModeDefault`.

## 2. Odrzucam

1. Kliknij `Odrzucam`.
2. Sprawdz `window.ReklamovaConsent.getState()`.
3. Kategorie poza `necessary` powinny byc `false`.
4. Skrypty marketingowe i analityczne nie powinny pojawic sie w Network.
5. Kliknij link `Ustawienia prywatnosci` w stopce i zmien decyzje.

## 3. Akceptuje wszystko

1. Kliknij `Akceptuje wszystko`.
2. Sprawdz, czy laduja sie aktywne skrypty wszystkich kategorii.
3. Sprawdz, czy Consent Mode ma `granted` dla odpowiednich typow.

## 4. Dostosuj

1. Kliknij `Dostosuj`.
2. Wlacz tylko `analytics`.
3. Sprawdz, ze GA4/Clarity moga sie zaladowac, ale Meta Pixel/Google Ads pozostaja zablokowane.

## 5. Meta Pixel

1. Dodaj skrypt Meta Pixel w `/admin/privacy/scripts`.
2. Przypisz kategorie `marketing`.
3. Otworz strone jako nowy uzytkownik.
4. Przed zgoda marketingowa Pixel nie moze sie zaladowac.
5. Po zgodzie marketingowej Pixel powinien sie zaladowac.

## 6. Skrypt tylko na jednej podstronie

1. W edycji podstrony kliknij `Dodaj skrypt do tej podstrony`.
2. Zapisz skrypt z zakresem `selected_pages`.
3. Wejdz na wskazany URL i zaakceptuj wymagana kategorie.
4. Sprawdz, ze skrypt dziala tylko tam.

## 7. Awaryjne wylaczenie

1. Wejdz w `/admin/privacy/scripts`.
2. Wlacz awaryjne wylaczenie skryptow.
3. Otworz frontend.
4. Baner i panel ustawien nadal dzialaja.
5. API skryptow zwraca pusta liste.

## 8. Panel admina

1. Wklej custom script z HTML/JS.
2. Sprawdz, ze kod jest widoczny jako tekst i nie wykonuje sie w panelu.
3. Sprobuj wkleic `<?php echo 1; ?>`.
4. Zapis powinien zostac zablokowany.

## 9. Rollback

1. Zapisz skrypt.
2. Zmien jego kod i zapisz ponownie.
3. W historii wersji kliknij `Przywroc`.
4. Kod powinien wrocic do poprzedniej wersji, a rollback powinien pojawic sie w audycie.

## 10. Dokumenty

1. Edytuj szkic polityki prywatnosci.
2. Opublikuj dokument.
3. Wejdz na `/polityka-prywatnosci`.
4. Sprawdz podstawienie zmiennych i date publikacji.
