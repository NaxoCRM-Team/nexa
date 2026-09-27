<?php

namespace Espo\Custom\Tools\LandingPage;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;

final class LandingPageRenderer
{
    public function __construct(private LandingPageService $service, private EntityManager $entityManager, private Config $config) {}

    public function render(string $key, string $slug): string
    {
        $published = $this->service->published($key, $slug); $config = $published['configuration']; $page = $published['page'];
        $this->service->recordEvent($key, $slug, 'view', null, $_SERVER['HTTP_REFERER'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
        $title = $this->e((string) ($config['seoTitle'] ?? $page['name'])); $description = $this->e((string) ($config['seoDescription'] ?? ''));
        $canonical = trim((string) ($config['canonicalUrl'] ?? '')); $robots = !empty($config['noIndex']) ? '<meta name="robots" content="noindex,nofollow">' : '';
        $styles = '--primary:' . $this->color($config['primaryColor'] ?? null, '#087d71') . ';--background:' . $this->color($config['backgroundColor'] ?? null, '#ffffff') . ';--text:' . $this->color($config['textColor'] ?? null, '#172f35');
        $blocks = ''; foreach (($config['blocks'] ?? []) as $block) $blocks .= $this->block($block, $key, $slug, $page);
        return '<!doctype html><html lang="' . $this->e((string) ($config['locale'] ?? 'en')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title><meta name="description" content="' . $description . '">' . $robots . ($canonical ? '<link rel="canonical" href="' . $this->e($canonical) . '">' : '') . '<style>' . $this->css() . '</style></head><body style="' . $styles . '"><main>' . ($blocks ?: '<section class="empty"><h1>' . $this->e((string) $page['name']) . '</h1></section>') . '</main><footer>&copy; ' . gmdate('Y') . ' ' . $this->e((string) ($page['name'] ?? '')) . '</footer></body></html>';
    }

    /** @param array<string, mixed> $block @param array<string, mixed> $page */
    private function block(array $block, string $key, string $slug, array $page): string
    {
        $type = (string) ($block['type'] ?? ''); $id = $this->e((string) ($block['id'] ?? 'section'));
        $heading = $this->e((string) ($block['heading'] ?? '')); $text = nl2br($this->e((string) ($block['text'] ?? ''))); $eyebrow = $this->e((string) ($block['eyebrow'] ?? ''));
        $imageUrl = !empty($block['assetId']) ? $this->assetUrl($key, $slug, (string) $block['assetId']) : $this->templateImageUrl((string) ($block['templateImage'] ?? ''));
        $image = $imageUrl ? '<img src="' . $this->e($imageUrl) . '" alt="' . $this->e((string) ($block['altText'] ?? '')) . '">' : '';
        $destination = (string) ($block['buttonUrl'] ?? '');
        $buttonUrl = str_starts_with($destination, '#') ? $destination : ($destination ? $this->clickUrl($key, $slug, (string) $block['id'], $destination) : '');
        $button = (!empty($block['buttonLabel']) && $buttonUrl) ? '<a class="button" href="' . $this->e($buttonUrl) . '">' . $this->e((string) $block['buttonLabel']) . '</a>' : '';
        return match ($type) {
            'hero' => '<section id="' . $id . '" class="hero"><div class="copy">' . ($eyebrow ? '<span class="eyebrow">' . $eyebrow . '</span>' : '') . '<h1>' . $heading . '</h1><p>' . $text . '</p>' . $button . '</div>' . ($image ? '<div class="media">' . $image . '</div>' : '') . '</section>',
            'text' => '<section id="' . $id . '" class="content"><div><h2>' . $heading . '</h2><p>' . $text . '</p></div></section>',
            'image' => '<section id="' . $id . '" class="image">' . $image . (!empty($block['caption']) ? '<p>' . $this->e((string) $block['caption']) . '</p>' : '') . '</section>',
            'form' => $this->formBlock($block, $heading, $text, $id, $page),
            'cta' => '<section id="' . $id . '" class="cta"><div><h2>' . $heading . '</h2><p>' . $text . '</p></div>' . $button . '</section>',
            'features' => '<section id="' . $id . '" class="features">' . $this->sectionHeading($eyebrow, $heading, $text) . '<div class="feature-grid">' . $this->items($block, 'feature') . '</div></section>',
            'stats' => '<section id="' . $id . '" class="stats">' . $this->sectionHeading('', $heading, $text) . '<div class="stat-grid">' . $this->items($block, 'stat') . '</div></section>',
            'testimonial' => '<section id="' . $id . '" class="testimonial"><blockquote><span aria-hidden="true">&ldquo;</span>' . $heading . '</blockquote><p>' . $text . '</p>' . $this->items($block, 'person') . '</section>',
            'divider' => '<hr id="' . $id . '">',
            default => '',
        };
    }

    /** @param array<string, mixed> $block */
    private function items(array $block, string $class): string
    {
        $html = '';
        foreach (($block['items'] ?? []) as $item) {
            $title = $this->e((string) ($item['title'] ?? ''));
            $text = $this->e((string) ($item['text'] ?? ''));
            $meta = $this->e((string) ($item['meta'] ?? ''));
            $html .= '<article class="' . $class . '"><strong>' . $title . '</strong>' . ($text ? '<p>' . $text . '</p>' : '') . ($meta ? '<small>' . $meta . '</small>' : '') . '</article>';
        }
        return $html;
    }

    private function sectionHeading(string $eyebrow, string $heading, string $text): string
    {
        return '<div class="section-heading">' . ($eyebrow ? '<span class="eyebrow">' . $eyebrow . '</span>' : '') . '<h2>' . $heading . '</h2><p>' . $text . '</p></div>';
    }

    private function templateImageUrl(string $name): ?string
    {
        if (!in_array($name, ['request-demo.jpg','consultation.jpg','event-registration.jpg','lead-magnet.jpg'], true)) return null;
        return rtrim((string) $this->config->get('siteUrl'), '/') . '/client/custom/img/landing-templates/' . rawurlencode($name);
    }

    /** @param array<string, mixed> $block @param array<string, mixed> $page */
    private function formBlock(array $block, string $heading, string $text, string $id, array $page): string
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT l.form_id FROM lead_capture l INNER JOIN nexa_form_profile p ON p.lead_capture_id=l.id AND p.tenant_id=l.tenant_id AND p.service_id=l.service_id WHERE l.id=? AND l.tenant_id=? AND l.service_id=? AND p.status='published' AND l.deleted=0 LIMIT 1");
        $statement->execute([(string) ($block['formId'] ?? ''), $page['tenant_id'], $page['service_id']]); $publicId = $statement->fetchColumn(); if (!$publicId) return '';
        $url = rtrim((string) $this->config->get('siteUrl'), '/') . '/?entryPoint=LeadCaptureForm&id=' . rawurlencode((string) $publicId);
        return '<section id="' . $id . '" class="form"><div class="form-copy"><h2>' . $heading . '</h2><p>' . $text . '</p></div><iframe title="' . ($heading ?: 'Contact form') . '" src="' . $this->e($url) . '" loading="lazy"></iframe></section>';
    }

    private function assetUrl(string $key, string $slug, string $asset): string { return rtrim((string) $this->config->get('siteUrl'), '/') . '/?entryPoint=NexaLandingAsset&key=' . rawurlencode($key) . '&slug=' . rawurlencode($slug) . '&asset=' . rawurlencode($asset); }
    private function clickUrl(string $key, string $slug, string $target, string $to): string { return rtrim((string) $this->config->get('siteUrl'), '/') . '/?entryPoint=NexaLandingClick&key=' . rawurlencode($key) . '&slug=' . rawurlencode($slug) . '&target=' . rawurlencode($target) . '&to=' . rawurlencode($to); }
    private function color(mixed $value, string $fallback): string { $value = strtolower(trim((string) $value)); return preg_match('/^#[a-f0-9]{6}$/', $value) ? $value : $fallback; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function css(): string { return '*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--background);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif;line-height:1.55;letter-spacing:0}main{min-height:calc(100vh - 70px)}section{padding:84px max(24px,calc((100vw - 1160px)/2))}.hero{display:grid;grid-template-columns:minmax(0,1.04fr) minmax(360px,.96fr);align-items:center;gap:64px;min-height:82vh}.hero h1{max-width:15ch;margin:14px 0 22px;font-size:clamp(44px,5.8vw,76px);font-weight:760;line-height:1.03}.hero p,.content p,.cta p,.form-copy p,.section-heading p{font-size:18px;color:color-mix(in srgb,var(--text) 72%,transparent)}.eyebrow{display:block;color:var(--primary);font-size:13px;font-weight:780;text-transform:uppercase}.media{height:min(68vh,650px)}.media img,.image img{display:block;width:100%;height:100%;max-height:650px;object-fit:cover;border-radius:8px}.button{display:inline-flex;margin-top:20px;padding:13px 20px;background:var(--primary);color:#fff;text-decoration:none;font-weight:750;border-radius:5px}.button:hover{filter:brightness(.9)}.content>div{max-width:780px}.content h2,.cta h2,.form h2,.section-heading h2{font-size:clamp(30px,4vw,48px);line-height:1.12;margin:8px 0 14px}.image p{text-align:center;color:color-mix(in srgb,var(--text) 68%,transparent)}.section-heading{max-width:750px;margin-bottom:38px}.feature-grid,.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}.feature{padding:28px 0;border-top:3px solid var(--primary)}.feature strong{display:block;font-size:21px}.feature p,.stat p,.person p{color:color-mix(in srgb,var(--text) 69%,transparent)}.stats{background:color-mix(in srgb,var(--primary) 7%,var(--background))}.stats .section-heading{margin-bottom:30px}.stat{padding:8px 28px 8px 0;border-right:1px solid color-mix(in srgb,var(--text) 16%,transparent)}.stat:last-child{border-right:0}.stat strong{display:block;color:var(--primary);font-size:42px;line-height:1}.testimonial{padding-top:100px;padding-bottom:100px;background:var(--text);color:var(--background)}.testimonial blockquote{position:relative;max-width:920px;margin:0;font-size:clamp(30px,4.5vw,54px);font-weight:700;line-height:1.18}.testimonial blockquote span{position:absolute;left:-27px;color:var(--primary)}.testimonial>p{max-width:700px;color:color-mix(in srgb,var(--background) 76%,transparent);font-size:18px}.person{margin-top:28px}.person strong,.person p,.person small{display:inline;margin-right:8px}.person p,.person small{color:color-mix(in srgb,var(--background) 70%,transparent)}.form{display:grid;grid-template-columns:minmax(240px,.72fr) minmax(360px,1.28fr);gap:60px;background:color-mix(in srgb,var(--primary) 6%,var(--background))}.form iframe{width:100%;min-height:560px;border:1px solid color-mix(in srgb,var(--text) 18%,transparent);background:#fff;border-radius:8px}.cta{display:flex;align-items:center;justify-content:space-between;gap:42px;background:color-mix(in srgb,var(--primary) 11%,var(--background))}.cta>div{max-width:760px}hr{border:0;border-top:1px solid color-mix(in srgb,var(--text) 15%,transparent);margin:0 max(24px,calc((100vw - 1160px)/2))}footer{padding:24px;text-align:center;font-size:13px;color:color-mix(in srgb,var(--text) 65%,transparent)}@media(max-width:760px){section{padding:54px 20px}.hero,.form{grid-template-columns:1fr;min-height:auto;gap:34px}.hero h1{font-size:42px}.media{height:420px}.feature-grid,.stat-grid{grid-template-columns:1fr}.stat{border-right:0;border-bottom:1px solid color-mix(in srgb,var(--text) 16%,transparent);padding:15px 0}.cta{align-items:flex-start;flex-direction:column}.form iframe{min-height:620px}.testimonial blockquote span{position:static;margin-right:4px}}'; }
}
