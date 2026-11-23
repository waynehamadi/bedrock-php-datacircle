<?php

declare(strict_types=1);

namespace Datacircle\Bedrock;

use Aws\ResultInterface;

/**
 * Datacircle's four tools for Amazon Bedrock's Converse API in the AWS SDK for PHP: find the best GTM APIs. https://datacircle.dev
 *
 * Each tool is a toolSpec on Datacircle's open API, https://api.datacircle.dev/v1, which needs no key, for converse()'s and
 * converseStream()'s toolConfig. Bedrock leaves running a toolUse to the app: toolResults() and the calls after it run
 * Datacircle's, and give the model each answer as JSON text in a toolResult. What goes wrong, the model reads as
 * {"error": "..."}: an input that does not fit the tool's schema, an answer of 4xx in the API's words, a failed network.
 */
final class Datacircle
{
    public const LIST_APIS = 'datacircle_list_apis';
    public const BEST_PLANS = 'datacircle_best_plans';
    public const SEARCH_PLANS = 'datacircle_search_plans';
    public const PROVIDER = 'datacircle_provider';

    /** The four tools' names, in their order. Their answers name each other by these names. */
    public const NAMES = [self::LIST_APIS, self::BEST_PLANS, self::SEARCH_PLANS, self::PROVIDER];

    private const ABOUT = [
        self::LIST_APIS => "Lists the GTM APIs on Datacircle's sheet (https://datacircle.dev). GTM means go-to-market. GTM APIs are the APIs sales and marketing teams use. Gives each API's slug, how many plans and providers it has and its best plan. Call it first to find an API's slug.",
        self::BEST_PLANS => "Gives who is best for one API, like email-finder: its best providers' plans, best first by their score out of 100 on Datacircle's benchmark (https://datacircle.dev), each with its rank, provider, plan, $ per 1,000 calls and its page. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
        self::SEARCH_PLANS => "Searches every plan on Datacircle's sheet (https://datacircle.dev), or one API's, by provider or plan name, sorted by score, provider or price. Gives how many were found and up to limit of them. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
        self::PROVIDER => "Gives each API one provider sells and its rank on each on Datacircle's benchmark (https://datacircle.dev), with its best plan there, its best rank first. total is how many APIs it sells, ranked on how many of them it has a rank. For an API's best providers, call datacircle_best_plans.",
    ];

    /** Each tool's arguments: [description, kind, needed, bounds or choices], the kind what the check reads the model's value as. */
    private const FIELDS = [
        self::LIST_APIS => [
            'query' => ["Words of the API's name or slug, like email or linkedin profile: the APIs that hold the most of them come first. Left out, the sheet's order", 'text', false, null],
            'limit' => ['How many APIs to give, 1 to 200. 20 when left out', 'number', false, [1, 200]],
        ],
        self::BEST_PLANS => [
            'slug' => ["The API's slug, like email-finder or linkedin-profile, as datacircle_list_apis gives it", 'text', true, null],
            'limit' => ['How many plans to give, 1 to 50. 10 when left out', 'number', false, [1, 50]],
        ],
        self::SEARCH_PLANS => [
            'api' => ["Only this API's plans: its name or slug, like email-finder. A provider's name goes in query, never here. Left out, every API", 'text', false, null],
            'query' => ["Words that must all appear in the provider's or plan's name, like bright data. Left out, every plan", 'text', false, null],
            'sort' => ['score (best first, the default), provider (A to Z) or price ($ per 1,000, lowest first)', 'choice', false, ['score', 'provider', 'price']],
            'limit' => ['How many plans to give, 1 to 50. 10 when left out', 'number', false, [1, 50]],
            'offset' => ['How many found plans to skip first. 0 when left out', 'number', false, [0, null]],
        ],
        self::PROVIDER => [
            'slug' => ["The provider's slug, like bright-data, or its name, like Bright Data: the name is made a slug", 'text', true, null],
        ],
    ];

    /** Tells a model that sent a provider's name to an API's tool where a provider's plans are. */
    private const FOR_PROVIDER = "A provider's plans: datacircle_search_plans with its name as query, like bright data, or datacircle_provider.";

    /** Tells a model that sent an API's slug to the provider's tool where an API's best providers are. */
    private const FOR_API = "An API's best providers: datacircle_best_plans with the API's slug, like email-finder.";

    /**
     * The four tools as toolConfig entries, for converse()'s and converseStream()'s 'toolConfig' => ['tools' => ...]:
     * datacircle_list_apis (GET /apis), datacircle_best_plans (GET /apis/{slug}/best), datacircle_search_plans (GET /plans)
     * and datacircle_provider (GET /providers/{slug}). Add your own tools to the same list.
     *
     * @return list<array{toolSpec: array{name: string, description: string, inputSchema: array{json: array<string, mixed>}}}>
     */
    public static function tools(): array
    {
        return array_map(static fn (string $name): array => ['toolSpec' => self::toolSpec($name)], self::NAMES);
    }

    /**
     * One tool's toolSpec: its name, description and input schema.
     *
     * @return array{name: string, description: string, inputSchema: array{json: array<string, mixed>}}
     */
    public static function toolSpec(string $name): array
    {
        return ['name' => $name, 'description' => self::ABOUT[$name] ?? throw new \InvalidArgumentException('datacircle: '.self::quote($name)." is not one of its tools' names"),
            'inputSchema' => ['json' => self::inputSchema($name)]];
    }

    /**
     * A tool's input as a JSON schema.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(string $name): array
    {
        $fields = self::FIELDS[$name] ?? throw new \InvalidArgumentException('datacircle: '.self::quote($name)." is not one of its tools' names");
        $properties = [];
        foreach ($fields as $key => [$about, $kind, , $rule]) {
            $properties[$key] = match ($kind) {
                'number' => ['type' => 'integer', 'description' => $about, 'minimum' => $rule[0]] + (null === $rule[1] ? [] : ['maximum' => $rule[1]]),
                'choice' => ['type' => 'string', 'description' => $about, 'enum' => $rule],
                default => ['type' => 'string', 'description' => $about],
            };
        }
        $required = array_keys(array_filter($fields, static fn (array $field): bool => $field[2]));

        return ['type' => 'object', 'properties' => $properties] + ([] === $required ? [] : ['required' => $required]);
    }

    /**
     * Whether a toolUse is one of Datacircle's, by its name.
     */
    public static function handles(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /**
     * The model's message, to add to your messages before the tool results, as Bedrock takes it back: from converse()'s
     * result, or a converseStream() put together from its events (its result, its stream, or the list of the events you
     * kept while you read it).
     *
     * Each toolUse input is a JSON object, as Bedrock wants it: the SDK reads an empty input {} as an empty array, which it
     * would send back as [], so it is given back as an empty \stdClass. A blank text block beside other blocks is left out,
     * as Bedrock refuses one.
     *
     * @param ResultInterface|iterable<mixed> $from
     *
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    public static function message(ResultInterface|iterable $from): array
    {
        if ($from instanceof ResultInterface) {
            $from = $from->hasKey('stream') ? $from['stream'] : $from->toArray();
        }
        if (is_array($from) && isset($from['output']['message'])) {
            $from = $from['output']['message'];
        } elseif (is_array($from) && isset($from['stream']) && is_iterable($from['stream'])) {
            $from = $from['stream'];
        }
        $message = is_array($from) && isset($from['content']) && is_array($from['content'])
            ? ['role' => (string) ($from['role'] ?? 'assistant'), 'content' => array_values($from['content'])]
            : self::gather($from);

        $content = [];
        foreach ($message['content'] as $block) {
            if (is_array($block) && isset($block['toolUse']) && is_array($block['toolUse'])) {
                $block['toolUse']['input'] = self::inputObject($block['toolUse']['input'] ?? null);
            }
            $content[] = $block;
        }
        $kept = array_values(array_filter($content, static fn (mixed $block): bool => !is_array($block) || !array_key_exists('text', $block)
            || 1 !== count($block) || '' !== trim((string) $block['text'])));
        $message['content'] = [] === $kept ? $content : $kept;

        return $message;
    }

    /**
     * The toolUse blocks of the model's message, each ['toolUseId' => ..., 'name' => ..., 'input' => ...], in their order.
     * An empty list: the model used no tool.
     *
     * @param ResultInterface|iterable<mixed> $from what message() takes
     *
     * @return list<array{toolUseId: string, name: string, input: array<mixed>|\stdClass}>
     */
    public static function toolUses(ResultInterface|iterable $from): array
    {
        $uses = [];
        foreach (self::message($from)['content'] as $block) {
            if (is_array($block) && isset($block['toolUse']['name'])) {
                $uses[] = $block['toolUse'];
            }
        }

        return $uses;
    }

    /**
     * The user message that answers each toolUse of the model's message, in their order, as toolResult blocks:
     * Datacircle's run, your own tools' answered by $yours. null: the model used no tool.
     *
     * Bedrock wants a toolResult for each toolUse of a turn, with its toolUseId, in the next user message. Send message()
     * first, then this.
     *
     * @param ResultInterface|iterable<mixed> $from what message() takes: converse()'s result, or converseStream()'s events
     * @param (callable(array{toolUseId: string, name: string, input: array<mixed>|\stdClass}): mixed)|null $yours
     *        answers a toolUse of your own tools: a whole ['toolResult' => ...] block, a string (its text), or any other value
     *        (its JSON)
     * @param Client|null $client Where and through what the tools call: Datacircle's API through Guzzle when left out.
     *
     * @return array{role: 'user', content: list<array{toolResult: array<string, mixed>}>}|null
     *
     * @throws \InvalidArgumentException a toolUse of a tool that is none of Datacircle's, with no $yours to answer it
     */
    public static function toolResults(ResultInterface|iterable $from, ?callable $yours = null, ?Client $client = null): ?array
    {
        $content = [];
        foreach (self::toolUses($from) as $use) {
            if (self::handles($use['name'])) {
                $content[] = self::toolResult($use, $client);
            } elseif (null === $yours) {
                throw new \InvalidArgumentException('datacircle: the model used '.self::quote($use['name']).", which is none of Datacircle's tools: give toolResults() a callable that answers your own");
            } else {
                $answer = $yours($use);
                $content[] = is_array($answer) && isset($answer['toolResult']) ? $answer
                    : ['toolResult' => ['toolUseId' => $use['toolUseId'], 'content' => [['text' => is_string($answer) && '' !== trim($answer) ? $answer : self::json($answer)]]]];
            }
        }

        return [] === $content ? null : ['role' => 'user', 'content' => $content];
    }

    /**
     * The toolResult block that answers one of Datacircle's toolUse blocks, with its toolUseId: the answer as JSON text.
     *
     * @param array{toolUseId: string, name: string, input?: mixed} $toolUse
     *
     * @return array{toolResult: array{toolUseId: string, content: list<array{text: string}>}}
     *
     * @throws \InvalidArgumentException a toolUse of a tool that is none of Datacircle's
     */
    public static function toolResult(array $toolUse, ?Client $client = null): array
    {
        return ['toolResult' => ['toolUseId' => (string) $toolUse['toolUseId'],
            'content' => [['text' => self::json(self::run((string) $toolUse['name'], $toolUse['input'] ?? [], $client))]]]];
    }

    /**
     * Runs one of Datacircle's tools on the input the model sent, and gives what the model reads: the answer as an array,
     * or ['error' => ...].
     *
     * @param array<mixed>|\stdClass|string $input an array, the \stdClass of an empty input, or JSON text
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException a name that is none of the four tools'
     */
    public static function run(string $name, array|\stdClass|string $input = [], ?Client $client = null): array
    {
        if (!self::handles($name)) {
            throw new \InvalidArgumentException('datacircle: '.self::quote($name)." is not one of its tools' names: ".implode(', ', self::NAMES));
        }

        try {
            if (is_string($input)) {
                $input = '' === trim($input) ? [] : json_decode($input, true);
                if (!is_array($input)) {
                    throw new \InvalidArgumentException('The input is not a JSON object');
                }
            }
            $input = $input instanceof \stdClass ? (array) json_decode((string) json_encode($input), true) : $input;
            if ([] !== $input && array_is_list($input)) {
                throw new \InvalidArgumentException('The input is not an object: a list');
            }

            return self::call($name, self::check($name, $input), $client ?? new Client());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * The model's message put together from converseStream()'s events: each block by its contentBlockIndex, a text and a
     * reasoning text from their deltas, a toolUse from its start and the pieces of its input.
     *
     * @param iterable<mixed> $events
     *
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private static function gather(iterable $events): array
    {
        $role = 'assistant';
        $blocks = [];
        $inputs = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (isset($event['messageStart']['role'])) {
                $role = (string) $event['messageStart']['role'];
            }
            $start = $event['contentBlockStart'] ?? null;
            if (is_array($start) && isset($start['start']['toolUse'])) {
                $index = (int) ($start['contentBlockIndex'] ?? count($blocks));
                $use = $start['start']['toolUse'];
                $blocks[$index] = ['toolUse' => ['toolUseId' => (string) ($use['toolUseId'] ?? ''), 'name' => (string) ($use['name'] ?? ''), 'input' => null]];
                $inputs[$index] = '';
            }
            $delta = $event['contentBlockDelta'] ?? null;
            if (!is_array($delta) || !is_array($delta['delta'] ?? null)) {
                continue;
            }
            $index = (int) ($delta['contentBlockIndex'] ?? 0);
            $piece = $delta['delta'];
            if (isset($piece['text'])) {
                $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '').$piece['text'];
            } elseif (isset($piece['toolUse']) && isset($inputs[$index])) {
                $inputs[$index] .= (string) ($piece['toolUse']['input'] ?? '');
            } elseif (isset($piece['reasoningContent']) && is_array($piece['reasoningContent'])) {
                $reasoning = $piece['reasoningContent'];
                $kept = $blocks[$index]['reasoningContent'] ?? [];
                if (isset($reasoning['text'])) {
                    $kept['reasoningText']['text'] = ($kept['reasoningText']['text'] ?? '').$reasoning['text'];
                }
                if (isset($reasoning['signature'])) {
                    $kept['reasoningText']['signature'] = ($kept['reasoningText']['signature'] ?? '').$reasoning['signature'];
                }
                if (isset($reasoning['redactedContent'])) {
                    $kept['redactedContent'] = ($kept['redactedContent'] ?? '').$reasoning['redactedContent'];
                }
                $blocks[$index]['reasoningContent'] = $kept;
            }
        }
        foreach ($inputs as $index => $input) {
            $decoded = '' === trim($input) ? [] : json_decode($input, true);
            $blocks[$index]['toolUse']['input'] = is_array($decoded) ? $decoded : [];
        }
        ksort($blocks);

        return ['role' => $role, 'content' => array_values($blocks)];
    }

    /**
     * A toolUse input as Bedrock takes it back: an empty one as an empty JSON object.
     *
     * @return array<mixed>|\stdClass
     */
    private static function inputObject(mixed $input): array|\stdClass
    {
        return is_array($input) && [] !== $input ? $input : ($input instanceof \stdClass ? $input : new \stdClass());
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) ?: '{"error": "The answer could not be written as JSON"}';
    }

    // The same calls without a model: each gives the answer the model would read, as an array, an answer of 4xx as
    // ['error' => the API's words]. An input that does not fit the tool's schema throws \InvalidArgumentException.

    /**
     * GET /apis: the APIs, as datacircle_list_apis gives them.
     *
     * @param array{query?: string|null, limit?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function listApis(array $input = [], ?Client $client = null): array
    {
        return self::call(self::LIST_APIS, self::check(self::LIST_APIS, $input), $client ?? new Client());
    }

    /**
     * GET /apis/{slug}/best: an API's plans best first, as datacircle_best_plans gives them.
     *
     * @param array{slug: string, limit?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function bestPlans(array $input, ?Client $client = null): array
    {
        return self::call(self::BEST_PLANS, self::check(self::BEST_PLANS, $input), $client ?? new Client());
    }

    /**
     * GET /plans: the plans found, as datacircle_search_plans gives them.
     *
     * @param array{api?: string|null, query?: string|null, sort?: 'score'|'provider'|'price'|null, limit?: int|null, offset?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function searchPlans(array $input = [], ?Client $client = null): array
    {
        return self::call(self::SEARCH_PLANS, self::check(self::SEARCH_PLANS, $input), $client ?? new Client());
    }

    /**
     * GET /providers/{slug}: where a provider ranks, as datacircle_provider gives it.
     *
     * @param array{slug: string} $input
     *
     * @return array<string, mixed>
     */
    public static function provider(array $input, ?Client $client = null): array
    {
        return self::call(self::PROVIDER, self::check(self::PROVIDER, $input), $client ?? new Client());
    }

    /**
     * The model's input checked against the tool's fields, looser than the schema, so that a model that strays is
     * understood where it can be: null is a key left out, and so is an empty number or choice, a key of its own is
     * dropped, a number sent as a string is read as one, a fraction cut to its whole part. What it refuses, the model
     * reads why.
     *
     * @param array<mixed> $input
     *
     * @return array<string, string|int|null>
     *
     * @throws \InvalidArgumentException
     */
    private static function check(string $name, array $input): array
    {
        $checked = [];
        $wrong = [];
        foreach (self::FIELDS[$name] as $key => [, $kind, $needed, $rule]) {
            $value = $input[$key] ?? null;
            if (null === $value || ('' === $value && 'text' !== $kind)) {
                if ($needed) {
                    $wrong[] = $key.': required';
                }
                $checked[$key] = null;

                continue;
            }
            if ('number' === $kind) {
                if (!is_numeric($value)) {
                    $wrong[] = $key.': expected a number, got '.self::kind($value);

                    continue;
                }
                $checked[$key] = (int) $value;
            } elseif (!is_string($value)) {
                $wrong[] = $key.': expected a string, got '.self::kind($value);
            } elseif ('choice' === $kind && !in_array($value, $rule, true)) {
                $wrong[] = $key.': expected one of '.implode(', ', $rule).', got '.json_encode($value);
            } else {
                $checked[$key] = $value;
            }
        }
        if ([] !== $wrong) {
            throw new \InvalidArgumentException("The input does not fit the tool's schema: ".implode('; ', $wrong));
        }

        return $checked;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function call(string $name, array $input, Client $client): array
    {
        try {
            return match ($name) {
                self::LIST_APIS => self::listApisRun($client, $input),
                self::BEST_PLANS => self::bestPlansRun($client, $input),
                self::SEARCH_PLANS => self::searchPlansRun($client, $input),
                self::PROVIDER => self::providerRun($client, $input),
            };
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function listApisRun(Client $client, array $input): array
    {
        $apis = Client::objects($client->get('/apis'));
        $query = (string) $input['query'];
        $words = preg_split('/\s+/', strtolower(str_replace('-', ' ', $query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        foreach ($apis as $order => $api) {
            $name = strtolower((string) ($api['api'] ?? '').' '.str_replace('-', ' ', (string) ($api['slug'] ?? '')));
            $matched = count(array_filter($words, static fn (string $word): bool => str_contains($name, $word)));
            if ($matched > 0 || [] === $words) {
                $found[] = [$matched, $order, Client::brief($api, ['api', 'slug', 'plans', 'providers', 'best', 'bestPage', 'sheetPage'])];
            }
        }
        // The most words matched first, the sheet's order within each.
        usort($found, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $answer = [
            'total' => count($found),
            'apis' => array_column(array_slice($found, 0, self::bounded($input['limit'], 200) ?? 20), 2),
        ];
        if ([] !== $words && ([] === $found || $found[0][0] < count($words))) {
            // A provider's name sent as an API's, like Bright Data, whose data matches many APIs: where its plans are.
            $answer['note'] = "No API's name holds every word of ".self::quote($query).". A provider's plans: datacircle_search_plans with its name as query, or datacircle_provider.";
        }

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function bestPlansRun(Client $client, array $input): array
    {
        $api = Client::slugify((string) $input['slug']);
        if ('' === $api) {
            return ['error' => "Give an API's slug, like email-finder: datacircle_list_apis gives every slug. ".self::FOR_PROVIDER];
        }

        try {
            $answer = $client->get('/apis/'.$api.'/best', ['limit' => self::bounded($input['limit'], 50)]);
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }

            // A provider's name sent as the API's slug: the API's words, and where a provider's plans are.
            return ['error' => $e->getMessage().". datacircle_list_apis gives every API's slug. ".self::FOR_PROVIDER];
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function searchPlansRun(Client $client, array $input): array
    {
        $search = static fn (string $api, string $query): array => $client->get('/plans', [
            'api' => trim($api),
            'query' => trim($query),
            'sort' => $input['sort'],
            'limit' => self::bounded($input['limit'], 50),
            'offset' => null !== $input['offset'] && $input['offset'] > 0 ? $input['offset'] : null,
        ]);
        $api = (string) $input['api'];
        $query = (string) $input['query'];
        $notes = [];

        try {
            $answer = $search($api, $query);
        } catch (ApiError $e) {
            if (400 !== $e->status || !str_contains($e->getMessage(), 'api must be') || '' !== trim($query)) {
                throw $e;
            }
            // A provider's name sent as the API: the plans whose provider or plan name holds it.
            [$api, $query] = ['', $api];
            $answer = $search($api, $query);
            $notes[] = 'No API is named '.self::quote((string) $input['api']).': these are the plans whose provider or plan name holds it.';
        }
        if (0 === ($answer['total'] ?? null) && str_contains($query, '-')) {
            // A slug sent as a name, like bright-data, which no name holds: its words, like bright data. A name with a
            // hyphen, like A-Leads, is found as it is first.
            $words = implode(' ', preg_split('/[\s-]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            try {
                $answer = $search($api, $words);
                $notes[] = "No provider's or plan's name holds ".self::quote($query).': these are the plans whose name holds '.self::quote($words).'.';
            } catch (ApiError) {
                // The first answer stands.
            }
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);
        if ([] !== $notes) {
            $answer['note'] = implode(' ', $notes);
        }

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function providerRun(Client $client, array $input): array
    {
        $provider = Client::slugify((string) $input['slug']);
        if ('' === $provider) {
            return ['error' => "Give a provider's slug or name, like bright-data or Bright Data"];
        }

        try {
            $answer = $client->get('/providers/'.$provider);
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }

            // An API's slug sent as a provider's, like email-finder: the API's words, and where an API's best providers are.
            return ['error' => $e->getMessage().'. '.self::FOR_API];
        }

        $apis = [];
        foreach (Client::objects($answer['apis'] ?? []) as $order => $entry) {
            $plans = Client::objects($entry['plans'] ?? []);
            $kept = Client::brief($entry, ['api', 'slug', 'rank', 'bestPage']);
            $kept['plans'] = count($plans);
            $best = self::bestPlan($plans);
            if (null !== $best) {
                $kept['best'] = Client::brief($best, ['plan', 'score', 'perThousand', 'page']);
            }
            $apis[] = [$order, $kept];
        }
        // Its best rank first, the APIs where it has none last, in the API's order.
        $rank = static fn (array $api): float|int => is_int($api['rank'] ?? null) || is_float($api['rank'] ?? null) ? $api['rank'] : INF;
        usort($apis, static fn (array $a, array $b): int => [$rank($a[1]), $a[0]] <=> [$rank($b[1]), $b[0]]);
        $apis = array_column($apis, 1);

        unset($answer['apis']);
        // How many it sells and is ranked on before the list, so that a model reads them first.
        $answer['total'] = count($apis);
        $answer['ranked'] = count(array_filter($apis, static fn (array $api): bool => INF !== $rank($api)));
        $answer['apis'] = $apis;

        return $answer;
    }

    /**
     * A provider's best plan on an API: its lowest rank, the first when none has a rank.
     *
     * @param list<array<string, mixed>> $plans
     *
     * @return array<string, mixed>|null
     */
    private static function bestPlan(array $plans): ?array
    {
        $best = null;
        foreach ($plans as $plan) {
            $rank = $plan['rank'] ?? null;
            if ((is_int($rank) || is_float($rank)) && (null === $best || $rank < $best['rank'])) {
                $best = $plan;
            }
        }

        return $best ?? $plans[0] ?? null;
    }

    /** A limit the model sent, within the API's bounds, or null for the API's default. */
    private static function bounded(int|string|null $limit, int $high): ?int
    {
        return null === $limit || (int) $limit < 1 ? null : min($high, (int) $limit);
    }

    private static function quote(string $text): string
    {
        return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            is_bool($value) => 'a boolean',
            is_string($value) => 'a string',
            default => get_debug_type($value),
        };
    }
}
