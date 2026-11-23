<?php

declare(strict_types=1);

namespace Datacircle\Bedrock\Tests;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Datacircle\Bedrock\Client;
use Datacircle\Bedrock\Datacircle;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The tools through the SDK's own converse() and converseStream(), on a faked Bedrock: what the SDK sends and parses.
 */
final class LoopTest extends TestCase
{
    /** @var list<array<mixed>> each body the SDK sent to Bedrock */
    private array $sent = [];

    /** @var list<string> the same bodies as sent */
    private array $raw = [];

    /** @param list<Response> $answers Bedrock's answers, in order */
    private function bedrock(array $answers): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'http_handler' => function (RequestInterface $request) use (&$answers) {
                $this->raw[] = (string) $request->getBody();
                $this->sent[] = json_decode((string) $request->getBody(), true);

                return Create::promiseFor(array_shift($answers));
            },
        ]);
    }

    private static function api(?FakeHttp &$http = null): Client
    {
        $http = new FakeHttp(static fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
            '/v1/apis' => FakeHttp::json([['api' => 'Employee email', 'slug' => 'email-finder', 'plans' => 10, 'providers' => 3]]),
            '/v1/apis/email-finder/best' => FakeHttp::json(['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1,
                'plans' => [['rank' => 1, 'provider' => 'Emelia', 'plan' => 'Pro', 'score' => 76.4, 'perThousand' => 4.99, 'termsOfUse' => 'long']]]),
            default => FakeHttp::json(['error' => 'no such path'], 404),
        });

        return new Client('https://api.test/v1', $http);
    }

    /** @param array<mixed> $body */
    private static function converse(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** One event of AWS's event stream encoding. @param array<mixed> $payload */
    private static function frame(string $event, array $payload): string
    {
        $headers = '';
        foreach ([':event-type' => $event, ':content-type' => 'application/json', ':message-type' => 'event'] as $name => $value) {
            $headers .= chr(strlen($name)).$name.chr(7).pack('n', strlen($value)).$value;
        }
        $data = json_encode($payload);
        $prelude = pack('NN', 12 + strlen($headers) + strlen($data) + 4, strlen($headers));
        $message = $prelude.pack('N', crc32($prelude)).$headers.$data;

        return $message.pack('N', crc32($message));
    }

    private static function stream(): Response
    {
        $events = [
            self::frame('messageStart', ['role' => 'assistant']),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['reasoningContent' => ['text' => 'Look it ']]]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['reasoningContent' => ['text' => 'up.']]]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['reasoningContent' => ['signature' => 'sig']]]),
            self::frame('contentBlockStop', ['contentBlockIndex' => 0]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['text' => 'Let me ']]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['text' => 'check.']]),
            self::frame('contentBlockStop', ['contentBlockIndex' => 1]),
            self::frame('contentBlockStart', ['contentBlockIndex' => 2, 'start' => ['toolUse' => ['toolUseId' => 'tooluse_a', 'name' => 'datacircle_best_plans']]]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 2, 'delta' => ['toolUse' => ['input' => '{"slug": "email']]]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 2, 'delta' => ['toolUse' => ['input' => '-finder", "limit": 1}']]]),
            self::frame('contentBlockStop', ['contentBlockIndex' => 2]),
            self::frame('contentBlockStart', ['contentBlockIndex' => 3, 'start' => ['toolUse' => ['toolUseId' => 'tooluse_b', 'name' => 'datacircle_list_apis']]]),
            self::frame('contentBlockDelta', ['contentBlockIndex' => 3, 'delta' => ['toolUse' => ['input' => '']]]),
            self::frame('contentBlockStop', ['contentBlockIndex' => 3]),
            self::frame('messageStop', ['stopReason' => 'tool_use']),
            self::frame('metadata', ['usage' => ['inputTokens' => 1, 'outputTokens' => 1, 'totalTokens' => 2], 'metrics' => ['latencyMs' => 1]]),
        ];

        return new Response(200, ['Content-Type' => 'application/vnd.amazon.eventstream'], implode('', $events));
    }

    public function testConverseLoop(): void
    {
        $bedrock = $this->bedrock([
            self::converse(['output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Calling.'],
                ['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => 'datacircle_best_plans', 'input' => ['slug' => 'email-finder', 'limit' => 1]]],
                ['toolUse' => ['toolUseId' => 'tooluse_2', 'name' => 'get_time', 'input' => ['zone' => 'UTC']]],
                ['toolUse' => ['toolUseId' => 'tooluse_3', 'name' => 'datacircle_list_apis', 'input' => new \stdClass()]],
            ]]], 'stopReason' => 'tool_use', 'usage' => ['inputTokens' => 1, 'outputTokens' => 1, 'totalTokens' => 2], 'metrics' => ['latencyMs' => 1]]),
            self::converse(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Emelia.']]]], 'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 1, 'outputTokens' => 1, 'totalTokens' => 2], 'metrics' => ['latencyMs' => 1]]),
        ]);
        $getTime = ['toolSpec' => ['name' => 'get_time', 'description' => 'The time', 'inputSchema' => ['json' => ['type' => 'object', 'properties' => ['zone' => ['type' => 'string']]]]]];
        $toolConfig = ['tools' => [...Datacircle::tools(), $getTime]];
        $messages = [['role' => 'user', 'content' => [['text' => 'Who is best for email finding?']]]];
        $client = self::api($http);

        $result = $bedrock->converse(['modelId' => 'm', 'messages' => $messages, 'toolConfig' => $toolConfig]);
        self::assertSame([], $result['output']['message']['content'][3]['toolUse']['input']);  // the SDK's reading of {}
        $messages[] = Datacircle::message($result);
        $messages[] = Datacircle::toolResults($result, static fn (array $use): array => ['time' => '12:00 '.$use['input']['zone']], $client);
        $result = $bedrock->converse(['modelId' => 'm', 'messages' => $messages, 'toolConfig' => $toolConfig]);
        self::assertNull(Datacircle::toolResults($result));
        self::assertSame('Emelia.', Datacircle::message($result)['content'][0]['text']);

        self::assertSame(['/v1/apis/email-finder/best?limit=1', '/v1/apis'], $http->asked());
        $tools = $this->sent[0]['toolConfig']['tools'];
        self::assertSame([...Datacircle::NAMES, 'get_time'], array_map(static fn (array $t): string => $t['toolSpec']['name'], $tools));
        self::assertSame(Datacircle::inputSchema(Datacircle::SEARCH_PLANS), $tools[2]['toolSpec']['inputSchema']['json']);

        self::assertStringContainsString('"toolUseId":"tooluse_3","name":"datacircle_list_apis","input":{}', $this->raw[1]);
        [$assistant, $answers] = array_slice($this->sent[1]['messages'], 1);
        self::assertSame('assistant', $assistant['role']);
        self::assertSame('user', $answers['role']);
        self::assertSame(['tooluse_1', 'tooluse_2', 'tooluse_3'], array_map(static fn (array $b): string => $b['toolResult']['toolUseId'], $answers['content']));
        $best = json_decode($answers['content'][0]['toolResult']['content'][0]['text'], true);
        self::assertSame(['rank' => 1, 'provider' => 'Emelia', 'plan' => 'Pro', 'score' => 76.4, 'perThousand' => 4.99], $best['plans'][0]);
        self::assertSame('{"time":"12:00 UTC"}', $answers['content'][1]['toolResult']['content'][0]['text']);
        self::assertSame(1, json_decode($answers['content'][2]['toolResult']['content'][0]['text'], true)['total']);
    }

    public function testStream(): void
    {
        $bedrock = $this->bedrock([self::stream(), self::stream()]);
        $client = self::api($http);
        $messages = [['role' => 'user', 'content' => [['text' => 'Best email finder?']]]];

        // The events kept while the text is read.
        $events = [];
        $text = '';
        foreach ($bedrock->converseStream(['modelId' => 'm', 'messages' => $messages, 'toolConfig' => ['tools' => Datacircle::tools()]])['stream'] as $event) {
            $events[] = $event;
            $text .= $event['contentBlockDelta']['delta']['text'] ?? '';
        }
        self::assertSame('Let me check.', $text);
        $message = Datacircle::message($events);
        self::assertEquals(['role' => 'assistant', 'content' => [
            ['reasoningContent' => ['reasoningText' => ['text' => 'Look it up.', 'signature' => 'sig']]],
            ['text' => 'Let me check.'],
            ['toolUse' => ['toolUseId' => 'tooluse_a', 'name' => 'datacircle_best_plans', 'input' => ['slug' => 'email-finder', 'limit' => 1]]],
            ['toolUse' => ['toolUseId' => 'tooluse_b', 'name' => 'datacircle_list_apis', 'input' => new \stdClass()]],
        ]], $message);
        self::assertInstanceOf(\stdClass::class, $message['content'][3]['toolUse']['input']);
        $answers = Datacircle::toolResults($events, null, $client);
        self::assertSame(['tooluse_a', 'tooluse_b'], array_map(static fn (array $b): string => $b['toolResult']['toolUseId'], $answers['content']));

        // The result itself: message() reads its stream.
        $result = $bedrock->converseStream(['modelId' => 'm', 'messages' => $messages, 'toolConfig' => ['tools' => Datacircle::tools()]]);
        self::assertEquals($message, Datacircle::message($result));
        self::assertSame(['/v1/apis/email-finder/best?limit=1', '/v1/apis'], $http->asked());
    }

    public function testWhatMessageTakes(): void
    {
        $use = ['toolUseId' => 't1', 'name' => 'get_time', 'input' => []];
        $result = ['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => "\n"], ['toolUse' => $use]]]], 'stopReason' => 'tool_use'];
        $fixed = ['role' => 'assistant', 'content' => [['toolUse' => ['toolUseId' => 't1', 'name' => 'get_time', 'input' => new \stdClass()]]]];
        self::assertEquals($fixed, Datacircle::message($result));
        self::assertEquals($fixed, Datacircle::message($result['output']['message']));
        self::assertEquals($fixed, Datacircle::message($fixed));
        self::assertEquals(['role' => 'assistant', 'content' => [['text' => ' ']]],
            Datacircle::message(['role' => 'assistant', 'content' => [['text' => ' ']]]));
        self::assertSame([], Datacircle::toolUses(['role' => 'assistant', 'content' => [['text' => 'Hi']]]));
        self::assertNull(Datacircle::toolResults(['role' => 'assistant', 'content' => [['text' => 'Hi']]]));

        self::assertSame(['role' => 'user', 'content' => [['toolResult' => ['toolUseId' => 't1', 'content' => [['text' => 'noon']]]]]],
            Datacircle::toolResults($result, static fn (): string => 'noon'));
        self::assertSame([['toolResult' => ['toolUseId' => 't1', 'content' => [['text' => '""']]]]],
            Datacircle::toolResults($result, static fn (): string => '')['content']);
        self::assertSame([['toolResult' => ['toolUseId' => 't1', 'content' => [['json' => ['a' => 1]]], 'status' => 'success']]],
            Datacircle::toolResults($result, static fn (): array => ['toolResult' => ['toolUseId' => 't1', 'content' => [['json' => ['a' => 1]]], 'status' => 'success']])['content']);
        $this->expectExceptionMessage('datacircle: the model used "get_time", which is none of Datacircle\'s tools');
        Datacircle::toolResults($result);
    }
}
