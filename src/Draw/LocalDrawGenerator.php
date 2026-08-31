<?php

declare(strict_types=1);

namespace Lottery\Draw;

use Lottery\Games\Families\RulesFactory;
use Lottery\Games\Families\TrxWinGoRules;
use Lottery\Games\GameDefinition;

/**
 * Provably-fair local fallback: when the provider is unreachable the result is
 * derived from HMAC-SHA256(draw_secret, gameCode|issueNumber). It is stable —
 * re-running the draw for the same issue always yields the same numbers.
 */
class LocalDrawGenerator
{
    private string $secret;
    private RulesFactory $rules;

    public function __construct(string $secret, RulesFactory $rules)
    {
        $this->secret = $secret;
        $this->rules  = $rules;
    }

    /**
     * @return array{result:array,hash:string}
     */
    public function draw(GameDefinition $game, string $issueNumber): array
    {
        $random = new HmacRandom($this->secret, $game->code . '|' . $issueNumber);
        $rules  = $this->rules->forGame($game);
        $result = $rules->generate($random->callable());
        $hash   = $random->seedHash();

        if ($rules instanceof TrxWinGoRules) {
            $result = $rules->withChainReference($result, $hash, (int) substr($issueNumber, -9));
        }

        $result['verify'] = [
            'algorithm' => 'HMAC-SHA256',
            'seed'      => $game->code . '|' . $issueNumber,
            'hash'      => $hash,
        ];

        return ['result' => $result, 'hash' => $hash];
    }
}
