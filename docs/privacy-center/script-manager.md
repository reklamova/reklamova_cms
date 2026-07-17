# Script Manager

Panel: `/admin/privacy/scripts`.

Script Manager przechowuje skrypty globalne i per podstrona. Kazdy skrypt ma kategorie zgody, miejsce ladowania, zakres URL, priorytet, status, tryb testowy, kod albo URL zewnetrzny oraz historie wersji.

## Pola skryptu

- nazwa
- typ: `preset` albo `custom`
- provider
- kategoria zgody
- placement: `head`, `body_start`, `body_end`
- scope: `global`, `selected_pages`, `blog`, `cart`, `checkout`, `thank_you`, `urls`
- reguly URL i wykluczenia
- `async` / `defer`
- kod HTML/JS albo zewnetrzny URL
- opis celu, cookies, retencja, transfer poza EOG, notatka

## Zasady bezpieczenstwa

- PHP w kodzie jest blokowany.
- Custom script wymaga checkboxa potwierdzenia ryzyka.
- Kod w panelu jest wyswietlany jako tekst escapowany.
- Historia wersji zapisuje kod i ustawienia przy kazdym zapisie.
- Rollback tworzy kolejna wersje z przywrocona zawartoscia.
- Awaryjne wylaczenie z poziomu listy skryptow powoduje, ze API i hooki nie zwracaja publicznych skryptow.

## Presety integracji

W module sa klasy presetow:

- `GoogleTagManagerIntegration`
- `GoogleAnalyticsIntegration`
- `GoogleAdsIntegration`
- `MetaPixelIntegration`
- `MicrosoftClarityIntegration`
- `HotjarIntegration`
- `TikTokPixelIntegration`
- `LinkedInInsightIntegration`
- `GoogleMapsIntegration`
- `YouTubeEmbedIntegration`
- `CustomScriptIntegration`

MVP zawiera klasy i generatory kodu startowego. Nastepny krok to UI wizard, ktory bedzie budowal kod z pol `container_id`, `measurement_id`, `pixel_id` itd.

## Test skryptu tylko na jednej podstronie

1. Wejdz w `/admin/pages/edit`.
2. W sekcji `Skrypty / Prywatnosc` kliknij `Dodaj skrypt do tej podstrony`.
3. Formularz otworzy sie ze scope `selected_pages` i podpowiedzianym URL.
4. Zapisz skrypt.
5. Sprawdz frontend tej podstrony i innej podstrony.
