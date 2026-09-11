<?php

declare(strict_types=1);

namespace Reklamova\Cms\Support;

final class EmailTemplate
{
    /**
     * @param array<int, string> $paragraphs
     * @param array<string, string> $facts
     * @param array{label:string,url:string}|null $action
     */
    public function render(
        string $siteName,
        string $preheader,
        string $heading,
        array $paragraphs = [],
        array $facts = [],
        ?array $action = null,
        string $footer = '',
    ): string {
        $paragraphHtml = '';
        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) !== '') {
                $paragraphHtml .= '<p style="margin:0 0 18px;color:#343946;font-size:16px;line-height:1.65">' . nl2br($this->escape($paragraph)) . '</p>';
            }
        }
        $factsHtml = '';
        if ($facts !== []) {
            $factsHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;border-collapse:separate;border-spacing:0;background:#f5f6f8;border-radius:14px">';
            foreach ($facts as $label => $value) {
                $factsHtml .= '<tr><td style="padding:13px 18px;border-bottom:1px solid #e4e6eb;color:#676d7b;font-size:13px">' . $this->escape($label) . '</td><td align="right" style="padding:13px 18px;border-bottom:1px solid #e4e6eb;color:#171a21;font-size:14px;font-weight:700">' . $this->escape($value) . '</td></tr>';
            }
            $factsHtml .= '</table>';
        }
        $actionHtml = '';
        if ($action !== null && $this->safeUrl($action['url'] ?? '')) {
            $actionHtml = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0"><tr><td style="border-radius:10px;background:#3155ff"><a href="' . $this->escape($action['url']) . '" style="display:inline-block;padding:14px 22px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none">' . $this->escape($action['label']) . '</a></td></tr></table>';
        }

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . $this->escape($heading) . '</title><style>@media(max-width:620px){.email-shell{width:100%!important}.email-card{padding:30px 22px!important}.email-heading{font-size:30px!important}}</style></head><body style="margin:0;padding:0;background:#eef0f4;font-family:Arial,Helvetica,sans-serif"><div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $this->escape($preheader) . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0f4"><tr><td align="center" style="padding:34px 14px"><table class="email-shell" role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:100%"><tr><td style="padding:0 0 18px;color:#171a21;font-size:15px;font-weight:800;letter-spacing:.08em">REKLAMOVA <span style="color:#3155ff">/</span> ' . $this->escape($siteName) . '</td></tr><tr><td class="email-card" style="padding:44px;border-radius:18px;background:#ffffff;box-shadow:0 10px 35px rgba(23,26,33,.08)"><div style="width:44px;height:6px;margin-bottom:28px;border-radius:99px;background:#f6df28"></div><h1 class="email-heading" style="margin:0 0 22px;color:#171a21;font-size:38px;line-height:1.08;letter-spacing:-.035em">' . $this->escape($heading) . '</h1>' . $paragraphHtml . $factsHtml . $actionHtml . '</td></tr><tr><td style="padding:22px 6px;color:#747a88;font-size:12px;line-height:1.6">' . $this->escape($footer !== '' ? $footer : $siteName) . '</td></tr></table></td></tr></table></body></html>';
    }

    private function safeUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
