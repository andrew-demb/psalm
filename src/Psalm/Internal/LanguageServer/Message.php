<?php

declare(strict_types=1);

namespace Psalm\Internal\LanguageServer;

use AdvancedJsonRpc\Message as MessageBody;
use Override;
use Stringable;

use function array_pop;
use function explode;
use function strlen;

/**
 * @internal
 */
final class Message implements Stringable
{
    /**
     * @var string[]
     */
    public array $headers;

    /**
     * Parses a message
     */
    public static function parse(string $msg): Message
    {
        $obj = new self;
        $parts = explode("\r\n", $msg);
        $obj->body = MessageBody::parse(array_pop($parts));
        foreach ($parts as $line) {
            if ($line) {
                $pair = explode(': ', $line);
                if (isset($pair[1])) {
                    $obj->headers[$pair[0]] = $pair[1];
                }
            }
        }

        return $obj;
    }

    /**
     * @param string[] $headers
     */
    public function __construct(public ?MessageBody $body = null, array $headers = [])
    {
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/vscode-jsonrpc; charset=utf8';
        }
        $this->headers = $headers;
    }

    #[Override]
    public function __toString(): string
    {

        $body = (string)$this->body;
        $contentLength = strlen($body);
        $this->headers['Content-Length'] = (string) $contentLength;
        $headers = '';
        foreach ($this->headers as $name => $value) {
            $headers .= "$name: $value\r\n";
        }

        return $headers . "\r\n" . $body;
    }
}
