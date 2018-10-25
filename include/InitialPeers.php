<?php

namespace Arionum\Node;

/**
 * Class InitialPeers
 */
final class InitialPeers
{
    public const MINIMUM_PEERS_REQUIRED = 2;
    public const PRELOAD_ERROR = 'Unable to retrieve peers from the preload list.';
    public const PRELOAD_LIST = 'https://www.arionum.com/peers.txt';

    /**
     * @var array
     */
    private $peerList = [];

    /**
     * InitialPeers constructor.
     * @param array|null $peerList
     * @return void
     */
    public function __construct(?array $peerList = [])
    {
        $this->peerList = $peerList;
    }

    /**
     * Retrieve a peer from the initial peer list.
     * @return string
     * @throws Exception
     */
    public function get(): string
    {
        if (!$this->peerList || count($this->peerList) < self::MINIMUM_PEERS_REQUIRED) {
            $this->retrieveFromPreloadList();
        }

        return $this->selectPeer();
    }

    /**
     * Retrieve all available initial peers.
     * @return array
     * @throws Exception
     */
    public function getAll(): array
    {
        if (!$this->peerList || count($this->peerList) < self::MINIMUM_PEERS_REQUIRED) {
            $this->retrieveFromPreloadList();
        }

        return $this->peerList;
    }

    /**
     * @return string
     */
    private function selectPeer(): string
    {
        return $this->peerList[array_rand($this->peerList)];
    }

    /**
     * Retrieve a peer from
     *
     * @return void
     * @throws Exception
     */
    private function retrieveFromPreloadList(): void
    {
        $peerList = file(self::PRELOAD_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!$peerList || count($peerList) < self::MINIMUM_PEERS_REQUIRED) {
            throw new Exception(self::PRELOAD_ERROR);
        }

        $this->peerList = $peerList;
    }
}
