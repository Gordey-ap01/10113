<?php
declare(strict_types=1);
namespace Poisk;

final class Suppliers
{
    public const LABELS = ['taggsm' => 'TAGGSM', 'moba' => 'MOBA', 'dvsota' => 'DVSOTA', 'greenspark' => 'GreenSpark'];
    public const GREEN_LOCAL_SHOP = 249529;
    public function __construct(private HttpTransport $http) {}
    private static function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return new \DOMXPath($doc);
    }
    private static function cls(string $name): string { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")'; }
    private static function text(\DOMXPath $xp, string $path, ?\DOMNode $node = null): string
    {
        $n = $xp->query($path, $node)?->item(0);
        return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '') : '';
    }
    private static function attr(\DOMXPath $xp, string $path, string $attr, ?\DOMNode $node = null): string
    { return $xp->query($path, $node)?->item(0)?->attributes?->getNamedItem($attr)?->nodeValue ?? ''; }
    private static function prices(string $text): array
    {
        preg_match_all('/(\d[\d\s\x{00a0}]*)\s*(?:₽|р\.)/u', $text, $m);
        return array_values(array_unique(array_map(static fn($v) => (int) preg_replace('/\D/u', '', $v), $m[1] ?? [])));
    }
    public static function enrich(array $item): array
    {
        $n = mb_strtolower($item['name']);
        $item['part'] = preg_match('/диспле|экран/u', $n) ? 'display' : (preg_match('/аккумулятор|батарея|\bакб\b/u', $n) ? 'battery' : (preg_match('/нижн.*(?:плат|шлейф)|шлейф.*(?:заряд|системн)|плат.*(?:заряд|разъем|разъём)/u', $n) ? 'charging' : (preg_match('/задн.*крыш/u', $n) ? 'back' : 'other')));
        if (preg_match('/^(?:наклейк|защитн|стекло|пл[её]нк|клей\b|скотч|рамк|уплотн|сетк|пылезащит|коннектор|разъ[её]м|шлейф.{0,25}(?:диспле|экран|аккумулятор))/u', $n)) $item['part'] = 'other';
        $item['technology'] = preg_match('/amoled/iu', $n) ? 'AMOLED' : (str_contains($n, 'oled') ? 'OLED' : (preg_match('/tft|ips|in.cell|incell/iu', $n) ? 'TFT / IPS' : (str_contains($n, 'lcd') ? 'LCD' : null)));
        $item['origin'] = preg_match('/copy|копия|аналог|не\s?ориг/iu', $n) ? 'copy' : (preg_match('/ориг|\bor\b|or100|or new|подлинн/iu', $n) ? 'original_claimed' : 'unknown');
        $item['condition'] = preg_match('/восстанов|\bref\b|refurb|заменен.{0,12}стекло/iu', $n) ? 'restored' : (preg_match('~снят|разбор|б[./]у~u', $n) ? 'pulled' : (preg_match('/новый|\bnew\b/iu', $n) ? 'new' : 'unknown'));
        $item['stock'] ??= 'unknown'; $item['warehouse'] ??= null;
        $item['is_local'] = preg_match('/Комсомольск[-\s]на[-\s]Амуре/iu', $item['warehouse'] ?? '') === 1;
        $item['delivery'] ??= null; $item['delivery_cost'] ??= null;
        $item['quantity'] = null;
        return $item;
    }
    public static function matches(string $query, string $name): bool
    {
        if (preg_match('/iphone\s*(\d+)\s*(pro\s*max|pro|plus|mini)?/iu', $query, $m)) {
            preg_match_all('~(?:iphone\s*|/\s*(?:iphone\s*)?)' . preg_quote($m[1], '~') . '(?!\d)\s*(pro\s*max|pro|plus|mini)?~iu', $name, $variants, PREG_SET_ORDER);
            $wanted = strtolower(preg_replace('/\s/', '', $m[2] ?? ''));
            $modelFound = false;
            foreach ($variants as $variant) if ($wanted === strtolower(preg_replace('/\s/', '', $variant[1] ?? ''))) { $modelFound = true; break; }
            if (!$modelFound) return false;
            $query = str_replace($m[0], '', $query);
        }
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
        $name = mb_strtolower($name);
        foreach ($tokens as $t) if (mb_strlen($t) > 1 && !str_contains($name, $t)) return false;
        return true;
    }
    private static function nextPage(\DOMXPath $xp, int $page, string $parameter = 'page'): ?int
    {
        foreach ($xp->query('//a[@href]') as $a) {
            parse_str(parse_url($a->getAttribute('href'), PHP_URL_QUERY) ?: '', $params);
            if (isset($params[$parameter]) && (string) $params[$parameter] === (string) ($page + 1)) return $page < 20 ? $page + 1 : null;
        }
        return null;
    }
    public function search(string $source, string $query, string $part, int $page = 1): array
    {
        $suffix = ['display' => ' дисплей', 'battery' => ' аккумулятор', 'charging' => ' шлейф', 'back' => ' крышка', 'all' => ''][$part];
        $articleQuery = preg_match('/^[a-z0-9_-]+$/iD', $query) && (str_contains($query, '-') || str_contains($query, '_') || preg_match('/^(?:zm)?\d{4,}$/iD', $query));
        if ($articleQuery) $suffix = '';
        $q = $query . $suffix;
        $method = $source;
        $result = $this->$method($q, $page);
        $out = [];
        $detailChecks = 0;
        foreach ($result['items'] as $item) {
            // GreenSpark omits articles from search cards. For an exact article
            // lookup, verify a bounded number of candidate product pages.
            if ($source === 'greenspark' && $articleQuery && empty($item['sku']) && $detailChecks++ < 3) $item['sku'] = $this->greenArticle($item['url']);
            if (!self::matches($query, $item['name']) && !self::matches($query, $item['sku'] ?? '')) continue;
            $item = self::enrich($item);
            if ($part !== 'all' && $item['part'] !== $part) continue;
            $item['supplier'] = $source;
            $item['id'] = hash('sha256', $source . '|' . $item['url'] . '|' . ($item['warehouse'] ?? ''));
            $out[$item['id']] = $item;
        }
        return ['items' => array_values($out), 'context' => $result['context'], 'source_url' => $result['source_url'], 'scanned' => count($result['items']), 'page' => $page, 'next_page' => $result['next_page']];
    }
    private function greenArticle(string $url): string
    {
        $xp = self::dom($this->http->get($url));
        $data = json_decode(self::text($xp, '//script[@id="__NUXT_DATA__"]'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($data as $v) if (is_array($v) && isset($v['article'], $v['prices'], $v['name']) && is_int($v['article'])) {
            $article = self::nuxtRead($data, $v['article']);
            if (is_string($article)) return $article;
        }
        return '';
    }
    private function taggsm(string $q, int $page = 1): array
    {
        $url = 'https://taggsm.ru/index.php?' . http_build_query(['route' => 'product/searcho', 'filter_name' => $q, 'page' => $page]);
        $xp = self::dom($this->http->get($url)); $items = [];
        foreach ($xp->query('//*[' . self::cls('product-thumb') . ']') as $card) {
            $name = self::text($xp, './/*[' . self::cls('category_name') . ']', $card);
            $link = self::attr($xp, './/a[@href]', 'href', $card);
            if ($name === '' || !Http::allowed($link)) continue;
            $stockText = self::text($xp, './/*[' . self::cls('category_nalichie') . ']', $card);
            $prices = self::prices(self::text($xp, './/*[' . self::cls('category_price') . ']', $card));
            $stock = 'unknown';
            if (preg_match('/Хабаровск\s+(нет в наличии|есть в наличии|[^Х]{0,45}наличие)/u', $stockText, $m)) $stock = str_contains($m[1], 'нет') ? 'out' : (str_contains($m[1], 'есть') ? 'in' : 'order');
            $items[] = ['name' => $name, 'url' => $link, 'sku' => self::text($xp, './/*[' . self::cls('cat_articul') . ']', $card), 'price' => $prices[0] ?? null,
                'other_prices' => array_slice($prices, 1), 'price_type' => 'Цена на сайте', 'stock' => $stock, 'warehouse' => 'Хабаровск', 'stock_details' => mb_substr($stockText, 0, 1800)];
        }
        return ['items' => $items, 'context' => 'Проверено наличие в магазине Хабаровска. Доставка в Комсомольск-на-Амуре требует уточнения.', 'source_url' => $url, 'next_page' => self::nextPage($xp, $page)];
    }
    private function dvsota(string $q, int $page = 1): array
    {
        $url = 'https://parts.dvsota.ru/catalog/?' . http_build_query(['q' => $q, 'PAGEN_1' => $page]);
        $xp = self::dom($this->http->get($url)); $items = [];
        foreach ($xp->query('//*[' . self::cls('catalog_item') . ']') as $card) {
            $name = self::text($xp, './/*[' . self::cls('item-title') . ']//a', $card);
            $link = self::attr($xp, './/*[' . self::cls('item-title') . ']//a', 'href', $card);
            if ($name === '' || !str_starts_with($link, '/catalog/')) continue;
            $raw = self::text($xp, './/*[' . self::cls('item-stock') . ']', $card);
            $price = self::attr($xp, './/*[' . self::cls('price') . ' and @data-value]', 'data-value', $card);
            $article = self::attr($xp, './/*[' . self::cls('article_block') . ']', 'data-value', $card);
            $items[] = ['name' => $name, 'url' => 'https://parts.dvsota.ru' . $link, 'sku' => $article, 'price' => $price !== '' ? (float) $price : null,
                'other_prices' => [], 'price_type' => 'Цена на сайте', 'warehouse' => 'Владивосток · склад уточняется', 'stock' => str_contains($raw, 'Нет') ? 'out' : (preg_match('/Мало|Достаточно|Много|В наличии/u', $raw) ? 'in' : 'unknown'), 'stock_details' => $raw];
        }
        return ['items' => $items, 'context' => 'Город поставщика — Владивосток. Наличие относится к данным каталога; конкретный склад и доставку нужно уточнить.', 'source_url' => $url, 'next_page' => self::nextPage($xp, $page, 'PAGEN_1')];
    }
    private function moba(string $q, int $page = 1): array
    {
        $base = 'https://khabarovsk.moba.ru';
        $url = $base . '/catalog/?' . http_build_query(['q' => $q, 'page' => $page]);
        $xp = self::dom($this->http->get($url)); $items = [];
        foreach ($xp->query('//*[' . self::cls('product-grid') . ']') as $card) {
            $name = self::text($xp, './/*[' . self::cls('product-grid__name') . ']', $card);
            $link = self::attr($xp, './/a[contains(@href,"/catalog/")]', 'href', $card);
            if ($name === '' || $link === '') continue;
            if (str_starts_with($link, '/')) $link = $base . $link;
            if (!Http::allowed($link)) continue;
            $txt = self::text($xp, '.', $card);
            $prices = self::prices(self::text($xp, './/*[' . self::cls('product-grid__price') . ']', $card));
            $discount = self::prices(self::text($xp, './/*[' . self::cls('product-grid__discount_price') . ']', $card));
            preg_match('/Артикул:\s*([A-Za-z0-9_\-]+)/u', $txt, $sku);
            $arrival = self::text($xp, './/*[' . self::cls('product-grid_will-arrive') . ']', $card);
            $available = self::text($xp, './/*[' . self::cls('product-grid_avail') . ']/parent::*[not(contains(translate(@style," ",""),"display:none"))]', $card);
            $unavailable = self::text($xp, './/*[' . self::cls('product-grid_not-avail') . ' and not(contains(translate(@style," ",""),"display:none"))]', $card);
            $stock = $available !== '' ? 'in' : ($arrival !== '' ? 'order' : ($unavailable !== '' ? 'out' : 'unknown'));
            $items[] = ['name' => $name, 'url' => $link, 'sku' => $sku[1] ?? '', 'price' => $prices[0] ?? null, 'other_prices' => $discount,
                'price_type' => 'Цена на сайте', 'warehouse' => 'Хабаровск', 'stock' => $stock, 'stock_details' => $arrival !== '' ? $arrival : ($stock === 'in' ? 'В наличии' : ($unavailable ?: 'Уточнить'))];
        }
        return ['items' => $items, 'context' => 'Региональный сайт MOBA — Хабаровск. Дата поступления в магазин не равна доставке в Комсомольск-на-Амуре.', 'source_url' => $url, 'next_page' => self::nextPage($xp, $page)];
    }
    private static function nuxtRead(array $data, int $ref, int $depth = 0): mixed
    {
        if ($ref < 0 || !array_key_exists($ref, $data) || $depth > 16) return null;
        $v = $data[$ref];
        if (!is_array($v)) return $v;
        if (array_is_list($v) && isset($v[0]) && is_string($v[0]) && in_array($v[0], ['Reactive','ShallowReactive','Ref','ShallowRef'], true)) return self::nuxtRead($data, $v[1], $depth + 1);
        $out = [];
        foreach ($v as $k => $index) $out[$k] = is_int($index) ? self::nuxtRead($data, $index, $depth + 1) : $index;
        return $out;
    }
    private function greenspark(string $q, int $page = 1): array
    {
        $url = 'https://green-spark.ru/search/?' . http_build_query(['q' => $q, 'sectionId' => 0, 'onlyAvailable' => 'false', 'page' => $page, 'set_city' => self::GREEN_LOCAL_SHOP]);
        $xp = self::dom($this->http->get($url));
        $raw = self::text($xp, '//script[@id="__NUXT_DATA__"]');
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); $items = []; $shop = null;
        foreach ($data as $v) if (is_array($v) && isset($v['$scurrentShop']) && is_int($v['$scurrentShop'])) { $shop = self::nuxtRead($data, $v['$scurrentShop']); break; }
        if (!is_array($shop) || (int) ($shop['id'] ?? 0) !== self::GREEN_LOCAL_SHOP || ($shop['cityName'] ?? '') !== 'Комсомольск-на-Амуре') throw new \RuntimeException('GreenSpark не подтвердил выбранный магазин в Комсомольске-на-Амуре');
        $warehouse = ($shop['name'] ?? $shop['cityName']) . (!empty($shop['address']) ? ' · ' . $shop['address'] : '');
        foreach ($data as $v) {
            if (!is_array($v) || !isset($v['id'], $v['name'], $v['prices'], $v['url'])) continue;
            $get = static fn(string $key) => isset($v[$key]) && is_int($v[$key]) ? self::nuxtRead($data, $v[$key]) : null;
            $name = $get('name'); $article = $get('article');
            if (!is_string($name)) continue;
            $link = $get('url') ?? $get('detailPageUrl') ?? $get('link');
            if (!is_string($link)) {
                // Match the displayed product title to its actual catalogue link; never invent URLs.
                foreach ($xp->query('//a[contains(@href,"/catalog/")]') as $a) if (preg_replace('/\s+/u', '', $a->textContent) === preg_replace('/\s+/u', '', $name)) { $link = $a->getAttribute('href'); break; }
            }
            if (!is_string($link)) continue;
            if (str_starts_with($link, '/')) $link = 'https://green-spark.ru' . $link;
            if (!Http::allowed($link)) continue;
            $prices = $get('prices'); $p = is_array($prices) ? array_values(array_filter($prices, 'is_array')) : [];
            $quantity = $get('quantity');
            $items[] = ['name' => $name, 'url' => $link, 'sku' => is_string($article) ? $article : '', 'source_id' => $get('id'), 'price' => isset($p[0]['price']) ? (float) $p[0]['price'] : null,
                'other_prices' => array_values(array_map(static fn($x) => ['name' => $x['name'] ?? '', 'price' => $x['price'] ?? null], array_slice($p, 1))),
                'price_type' => $p[0]['name'] ?? 'Цена на сайте', 'warehouse' => $warehouse,
                'stock' => in_array($quantity, ['few','enough','many','much','available'], true) ? 'in' : (in_array($quantity, ['none','unavailable','not'], true) ? 'out' : 'unknown'),
                'stock_details' => ['few' => 'Мало', 'enough' => 'Достаточно', 'many' => 'Много', 'none' => 'Нет в наличии'][$quantity ?? ''] ?? (string) $quantity];
        }
        return ['items' => $items, 'context' => 'GreenSpark — Комсомольск-на-Амуре, проспект Ленина, 4. Магазин подтверждён ответом сайта; цены и наличие относятся к нему. Точный остаток и возможность самовывоза уточните перед поездкой.', 'source_url' => $url, 'next_page' => self::nextPage($xp, $page)];
    }
}
