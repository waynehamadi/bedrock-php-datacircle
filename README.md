# Datacircle tools for Amazon Bedrock in the AWS SDK for PHP

Find the best GTM APIs. We benchmark all the GTM APIs and we put the results on our website.

Our website: https://datacircle.dev

This package gives Amazon Bedrock's Converse API four tools that call Datacircle's open API, https://api.datacircle.dev/v1. You pass the tools to `converse()` or `converseStream()` of the [AWS SDK for PHP](https://github.com/aws/aws-sdk-php). Bedrock does not run tools. This package runs each call the model makes to a Datacircle tool. You get the answers to send back to the model. You need no key and no account.

| Tool | What the tool gives | Call |
| --- | --- | --- |
| `datacircle_list_apis` | Every GTM API on Datacircle, with the API's slug, number of plans, number of providers and best plan | `GET /apis` |
| `datacircle_best_plans` | One API's plans, sorted by score out of 100, best first. Each plan comes with a rank and a price per 1,000 calls | `GET /apis/{slug}/best` |
| `datacircle_search_plans` | Plans that match a provider or plan name, on all APIs or on one API. You can sort them by score, provider or price | `GET /plans` |
| `datacircle_provider` | The APIs a provider sells, and the provider's rank on each API | `GET /providers/{slug}` |

## Install

```bash
composer require datacircle/bedrock-tools
```

The package needs PHP 8.1 or later and `aws/aws-sdk-php` 3.371.4 or later. The tools send their calls through Guzzle. Composer installs Guzzle with the AWS SDK.

## Use the tools with converse()

Put `Datacircle::tools()` in `toolConfig`. Add the model's message to `$messages`, then the answers that `Datacircle::toolResults()` gives:

```php
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Datacircle\Bedrock\Datacircle;

$bedrock = new BedrockRuntimeClient(['region' => 'us-east-1']);

$messages = [['role' => 'user', 'content' => [['text' => 'Who are the 3 best providers for the email-finder API?']]]];
while (true) {
    $result = $bedrock->converse([
        'modelId' => 'amazon.nova-pro-v1:0',
        'messages' => $messages,
        'toolConfig' => ['tools' => Datacircle::tools()],
    ]);
    $message = Datacircle::message($result);
    $messages[] = $message;
    $answers = Datacircle::toolResults($result);
    if ($answers === null) {
        break;
    }
    $messages[] = $answers;
}

echo implode('', array_column($message['content'], 'text')), "\n";
```

`Datacircle::toolResults()` gives one user message with a `toolResult` for each tool the model used, in order. You get `null` when the model used no tool.

Add `Datacircle::message($result)` to your messages in place of `$result['output']['message']`. The AWS SDK reads an empty tool input `{}` as an empty PHP array. The SDK would then send that input back as `[]`. Bedrock refuses a message with `[]` as a tool input. `Datacircle::message()` sends the input back as `{}`.

## Use the tools with converseStream()

Keep each event of the stream in a list. After the stream ends, pass the list to `Datacircle::message()` and `Datacircle::toolResults()`:

```php
$messages = [['role' => 'user', 'content' => [['text' => 'Which provider ranks first for the SERP API?']]]];
while (true) {
    $result = $bedrock->converseStream([
        'modelId' => 'amazon.nova-pro-v1:0',
        'messages' => $messages,
        'toolConfig' => ['tools' => Datacircle::tools()],
    ]);
    $events = [];
    foreach ($result['stream'] as $event) {
        $events[] = $event;
        echo $event['contentBlockDelta']['delta']['text'] ?? '';
    }
    $messages[] = Datacircle::message($events);
    $answers = Datacircle::toolResults($events);
    if ($answers === null) {
        break;
    }
    $messages[] = $answers;
}
```

`Datacircle::message()` builds the model's message from the events: the text, the reasoning and each tool input. Pass the result instead of the events when your code does not read the stream: `$message = Datacircle::message($result)`. Then pass `$message` to `Datacircle::toolResults()`. You can read the stream only once.

## Your own tools

Bedrock needs an answer to each tool the model used in a turn. If you add tools of your own, pass a callable as the second argument of `Datacircle::toolResults()`. The callable gets each use of one of your tools, as an array with `toolUseId`, `name` and `input`. The callable returns the answer as a string, an array, or a whole `['toolResult' => ...]` block:

```php
$tools = [...Datacircle::tools(), $yourTool];

$answers = Datacircle::toolResults($result, fn (array $use) => ['time' => date('H:i')]);
```

Leave out the callable and `Datacircle::toolResults()` throws an `InvalidArgumentException` when the model uses one of your tools. `Datacircle::handles($name)` tells you if the tool named `$name` is one of ours. `Datacircle::toolResult($use)` answers one use of a Datacircle tool. `Datacircle::toolSpec($name)` gives one tool's spec.

## Make each call yourself

You can make each call without a model. You get the tool's answer as an array:

```php
use Datacircle\Bedrock\Datacircle;

$answer = Datacircle::bestPlans(['slug' => 'email-finder', 'limit' => 3]);
print_r($answer['plans']);
```

The other calls are `Datacircle::listApis()`, `Datacircle::searchPlans()` and `Datacircle::provider()`. `Datacircle::run($name, $input)` runs any call. Give `run()` the tool's name, such as `datacircle_best_plans`, and the input.

## What the model reads

Each tool gives the model a JSON object as the text of the `toolResult`. Each plan comes with a rank, provider, plan, score, price per 1,000 (`perThousand`, in dollars) and a page on datacircle.dev. That page lists every field.

For an unknown slug, the model reads `{"error": "..."}` with the API's message. If the input does not fit the schema, the error names each wrong field. If the network fails, the error gives the network's message. The model can read the error, call the tool again and keep going. A call without a model gives the API's message as `['error' => '...']`.

To send the calls through another PSR-18 client, pass `new \Datacircle\Bedrock\Client(http: $httpClient)` as the last argument of `toolResults()` or any other call. Use this to add a proxy or a timeout.

## Network

Each tool call sends HTTPS GET requests to https://api.datacircle.dev/v1 and nowhere else. Most calls send one request. `datacircle_search_plans` sends up to three when the first search finds nothing. The requests hold only the tool's input from the model: no key and nothing from your messages. The API takes up to 60 calls a minute from one IP address. The tool waits once, for a minute at most, when the API says to wait. The package stores nothing.

## Links

- Datacircle: https://datacircle.dev
- The API: https://datacircle.dev/api
- The API's spec: https://api.datacircle.dev/v1/openapi.json
- What each key means: https://datacircle.dev/fields

MIT license.
