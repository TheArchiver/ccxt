<?php

namespace ccxt;

class nova extends Exchange {

    public function describe () {
        return array_replace_recursive (parent::describe (), array (
            'id' => 'nova',
            'name' => 'Novaexchange',
            'countries' => 'TZ', // Tanzania
            'rateLimit' => 2000,
            'version' => 'v2',
            'hasCORS' => false,
            'urls' => array (
                'logo' => 'https://user-images.githubusercontent.com/1294454/30518571-78ca0bca-9b8a-11e7-8840-64b83a4a94b2.jpg',
                'api' => 'https://novaexchange.com/remote',
                'www' => 'https://novaexchange.com',
                'doc' => 'https://novaexchange.com/remote/faq',
            ),
            'api' => array (
                'public' => array (
                    'get' => array (
                        'markets/',
                        'markets/{basecurrency}/',
                        'market/info/{pair}/',
                        'market/orderhistory/{pair}/',
                        'market/openorders/{pair}/buy/',
                        'market/openorders/{pair}/sell/',
                        'market/openorders/{pair}/both/',
                        'market/openorders/{pair}/{ordertype}/',
                    ),
                ),
                'private' => array (
                    'post' => array (
                        'getbalances/',
                        'getbalance/{currency}/',
                        'getdeposits/',
                        'getwithdrawals/',
                        'getnewdepositaddress/{currency}/',
                        'getdepositaddress/{currency}/',
                        'myopenorders/',
                        'myopenorders_market/{pair}/',
                        'cancelorder/{orderid}/',
                        'withdraw/{currency}/',
                        'trade/{pair}/',
                        'tradehistory/',
                        'getdeposithistory/',
                        'getwithdrawalhistory/',
                        'walletstatus/',
                        'walletstatus/{currency}/',
                    ),
                ),
            ),
        ));
    }

    public function fetch_markets () {
        $response = $this->publicGetMarkets ();
        $markets = $response['markets'];
        $result = array ();
        for ($i = 0; $i < count ($markets); $i++) {
            $market = $markets[$i];
            if (!$market['disabled']) {
                $id = $market['marketname'];
                list ($quote, $base) = explode ('_', $id);
                $symbol = $base . '/' . $quote;
                $result[] = array (
                    'id' => $id,
                    'symbol' => $symbol,
                    'base' => $base,
                    'quote' => $quote,
                    'info' => $market,
                );
            }
        }
        return $result;
    }

    public function fetch_order_book ($symbol, $params = array ()) {
        $this->load_markets();
        $orderbook = $this->publicGetMarketOpenordersPairBoth (array_merge (array (
            'pair' => $this->market_id($symbol),
        ), $params));
        return $this->parse_order_book($orderbook, null, 'buyorders', 'sellorders', 'price', 'amount');
    }

    public function fetch_ticker ($symbol, $params = array ()) {
        $this->load_markets();
        $response = $this->publicGetMarketInfoPair (array_merge (array (
            'pair' => $this->market_id($symbol),
        ), $params));
        $ticker = $response['markets'][0];
        $timestamp = $this->milliseconds ();
        return array (
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'high' => floatval ($ticker['high24h']),
            'low' => floatval ($ticker['low24h']),
            'bid' => $this->safe_float($ticker, 'bid'),
            'ask' => $this->safe_float($ticker, 'ask'),
            'vwap' => null,
            'open' => null,
            'close' => null,
            'first' => null,
            'last' => floatval ($ticker['last_price']),
            'change' => floatval ($ticker['change24h']),
            'percentage' => null,
            'average' => null,
            'baseVolume' => null,
            'quoteVolume' => floatval ($ticker['volume24h']),
            'info' => $ticker,
        );
    }

    public function parse_trade ($trade, $market) {
        $timestamp = $trade['unix_t_datestamp'] * 1000;
        return array (
            'info' => $trade,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'symbol' => $market['symbol'],
            'id' => null,
            'order' => null,
            'type' => null,
            'side' => strtolower ($trade['tradetype']),
            'price' => floatval ($trade['price']),
            'amount' => floatval ($trade['amount']),
        );
    }

    public function fetch_trades ($symbol, $since = null, $limit = null, $params = array ()) {
        $this->load_markets();
        $market = $this->market ($symbol);
        $response = $this->publicGetMarketOrderhistoryPair (array_merge (array (
            'pair' => $market['id'],
        ), $params));
        return $this->parse_trades($response['items'], $market, $since, $limit);
    }

    public function fetch_balance ($params = array ()) {
        $this->load_markets();
        $response = $this->privatePostGetbalances ();
        $balances = $response['balances'];
        $result = array ( 'info' => $response );
        for ($b = 0; $b < count ($balances); $b++) {
            $balance = $balances[$b];
            $currency = $balance['currency'];
            $lockbox = floatval ($balance['amount_lockbox']);
            $trades = floatval ($balance['amount_trades']);
            $account = array (
                'free' => floatval ($balance['amount']),
                'used' => $this->sum ($lockbox, $trades),
                'total' => floatval ($balance['amount_total']),
            );
            $result[$currency] = $account;
        }
        return $this->parse_balance($result);
    }

    public function create_order ($symbol, $type, $side, $amount, $price = null, $params = array ()) {
        if ($type == 'market')
            throw new ExchangeError ($this->id . ' allows limit orders only');
        $this->load_markets();
        $amount = (string) $amount;
        $price = (string) $price;
        $market = $this->market ($symbol);
        $order = array (
            'tradetype' => strtoupper ($side),
            'tradeamount' => $amount,
            'tradeprice' => $price,
            'tradebase' => 1,
            'pair' => $market['id'],
        );
        $response = $this->privatePostTradePair (array_merge ($order, $params));
        return array (
            'info' => $response,
            'id' => null,
        );
    }

    public function cancel_order ($id, $symbol = null, $params = array ()) {
        return $this->privatePostCancelorder (array_merge (array (
            'orderid' => $id,
        ), $params));
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $url = $this->urls['api'] . '/' . $this->version . '/';
        if ($api == 'private')
            $url .= $api . '/';
        $url .= $this->implode_params($path, $params);
        $query = $this->omit ($params, $this->extract_params($path));
        if ($api == 'public') {
            if ($query)
                $url .= '?' . $this->urlencode ($query);
        } else {
            $this->check_required_credentials();
            $nonce = (string) $this->nonce ();
            $url .= '?' . $this->urlencode (array ( 'nonce' => $nonce ));
            $signature = $this->hmac ($this->encode ($url), $this->encode ($this->secret), 'sha512', 'base64');
            $body = $this->urlencode (array_merge (array (
                'apikey' => $this->apiKey,
                'signature' => $signature,
            ), $query));
            $headers = array (
                'Content-Type' => 'application/x-www-form-urlencoded',
            );
        }
        return array ( 'url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers );
    }

    public function request ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $response = $this->fetch2 ($path, $api, $method, $params, $headers, $body);
        if (is_array ($response) && array_key_exists ('status', $response))
            if ($response['status'] != 'success')
                throw new ExchangeError ($this->id . ' ' . $this->json ($response));
        return $response;
    }
}
