<?php
/*
 *   Copyright (C) 2016 Dang Duong
 *
 *   This file is part of Free Caro Online.
 *
 *   Free Caro Online is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *   Free Caro Online is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *   You should have received a copy of the GNU Affero General Public License
 *   along with Free Caro Online.  If not, see <http://www.gnu.org/licenses/>.
 */
if ( ! defined('BASEPATH')) exit('No direct script access allowed');

Class Game_model extends CI_Model
{
        public function __construct()
        {
                parent::__construct();
        } 

	public function showGamesList(){
		$this->db->select("gameId, player1Id, player2Id, player1Wins, player2Wins");
		return $this->db->get("{CARO_PREFIX}games")->result_array();
	}

        public function createGame($playerId){
		$gameId = $this->checkPlayerGameExists($playerId);
                if ($gameId <= 0){

                        $this->db->insert('{CARO_PREFIX}games', array('player1Id' => $playerId));
                        if ($this->db->insert_id())
                                return intval($this->db->insert_id());
                }
                return $gameId;
        }

        public function getGameData($items, $where, $limit = 1){
                $this->db->select($items);
                $this->db->limit($limit);

                $query = $this->db->get_where('{CARO_PREFIX}games', $where);
                if ($query->num_rows() > 0)
                        return $query->row_array();
                return false;
        }

	public function updateGameData($itemsArray, $where){

		$this->db->update("{CARO_PREFIX}games", $itemsArray, $where);
		if ($this->db->affected_rows() > 0)
			return true;
		return false;
	}

	public function deleteGame($where){
		$this->db->delete("{CARO_PREFIX}games", $where);
	}

        public function joinGame($gameId, $playerId){
		$gameId = intval($gameId);
		$playerId = intval($playerId);
                if ($this->checkPlayerGameExists($playerId) <= 0){
                        if ($this->updateGameData(array('player2Id' => $playerId, 'status' => 1), array('gameId' => $gameId, 'player2Id' => 0)))
                                return $gameId;
                }
                return 0;
        }

        public function readyGame($playerId){
		$gameId = $this->checkPlayerGameExists($playerId);
                if ($gameId > 0){
			if ($this->updateGameData(array('player1Ready' => 1), array('gameId' => $gameId, 'player1Id' => $playerId, 'status' => 1)))
				return $gameId;
			if ($this->updateGameData(array('player2Ready' => 1), array('gameId' => $gameId, 'player2Id' => $playerId, 'status' => 1)))
				return $gameId;
                }
                return 0;
        }

        public function startGame($gameId){
		$gameId = intval($gameId);
		$matrix = array();
		for ($i = 0; $i < 15; $i++){
			$matrix[$i] = array();
			for ($j = 0; $j < 15; $j++)
				$matrix[$i][$j] = 0;
		}
                $this->updateGameData(array('status' => 2, 'moves' => json_encode($matrix)), array('gameId' => $gameId, 'player1Ready' => 1, 'player2Ready' => 1, 'status' => 1));
        }

        public function removePlayer($gameId, $playerId, $otherPlayerId = 0){
		$gameId = intval($gameId);
		$playerId = intval($playerId);
		$otherPlayerId = intval($otherPlayerId);

		if ($this->updateGameData(array('player2Id' => 0, 'player1Ready' => 0, 'player2Ready' => 0, 'turn' => 1, 'status' => 0), array('gameId' => $gameId, 'player2Id' => $playerId)))
			return;
		if ($otherPlayerId <= 0){
			$otherPlayer = $this->getGameData('player2Id', array('gameId' => $gameId, 'player1Id' => $playerId));
			if ($otherPlayer)
				$otherPlayerId = $otherPlayer['player2Id'];
		}
		if ($otherPlayerId <= 0)
			$this->deleteGame(array('gameId' => $gameId, 'player1Id' => $playerId, 'player2Id' => 0));
		else
			$this->updateGameData(array('player1Id' => $otherPlayerId, 'player2Id' => 0, 'player1Ready' => 0, 'player2Ready' => 0, 'turn' => 1, 'status' => 0), array('gameId' => $gameId, 'player1Id' => $playerId, 'player2Id' => $otherPlayerId));
        }

        public function updateMove($playerId, $moveX = 0, $moveY = 0){

		$gameData = $this->getGameData('gameId, player1Id, player2Id, player1Wins, player2Wins, draws, startTime, lastMove, moves, turn', "player1Id = ".$playerId." OR player2Id = ".$playerId." AND status = 2");
		if (!$gameData)
			return false;
		$gameId = intval($gameData["gameId"]);
		$gameData["player1Id"] = intval($gameData["player1Id"]);
		$gameData["player2Id"] = intval($gameData["player2Id"]);
		$gameData["turn"] = intval($gameData["turn"]);
		$turn = $gameData["turn"];
		$movesJson = $gameData["moves"];

		if (!$this->checkPlayerTimeout($gameId, $gameData['player1Id'], $gameData['player2Id']))
			return false;

                $where = array(
			       'gameId' => $gameId,
			       'player'.$turn.'Id' => $playerId,
			       'turn' => $turn,
			       'moves' => $movesJson,
			       'status' => 2
			       );

                $moves = json_decode($movesJson, true);

                if ($moves[$moveX][$moveY] != 0 || $moveX < 0 || $moveX >= 15 || $moveY < 0 || $moveY >= 15){
                        $autoMove = $this->checkEmpty($moves);
                        if ($autoMove){
                                $moveX = $autoMove['x'];
                                $moveY = $autoMove['y'];
                        }
                        else
                                return false;
                }

                $moves[$moveX][$moveY] = $turn;
                $newTurn = ($turn == 1) ? 2 : 1;

                $data = array(
			      'lastMove' => date("Y-m-d H:i:s"),
			      'moves' => json_encode($moves),
			      'turn' => $newTurn
			      );
		$winner = $this->checkWinner($moves);

		if ($winner >= 0){
			$emptyCount = 0;
			for ($i = 0; $i < 15; $i++){
				for ($j = 0; $j < 15; $j++){
					if ($moves[$i][$j] == 0)
						$emptyCount++;
				}
			}
			$data = array_merge($data, $this->updateGameResult($gameData['player1Id'], $gameData['player2Id'], $gameData['startTime'], $winner, $gameData["player".$winner."Wins"], $gameData["draws"]));

			$data["player1Ready"] = 0;
			$data["player2Ready"] = 0;
			$newTurn = (($emptyCount % 2) == 0) ? $newTurn : $turn;
			$this->db->insert('{CARO_PREFIX}records', array('player1Id' => $gameData['player1Id'], 'player2Id' => $gameData['player2Id'], 'startTime' => $gameData['startTime'], 'endTime' => strtotime(date("Y-m-d H:i:s"))));
			$data['status'] = 1;
		}

		return $this->updateGameData($data, $where);

	}

	public function checkPlayerTimeout($gameId, $player1Id, $player2Id){
		$resultArray = array();
		$result = 0;
		if ($player2Id > 0){
			$this->db->select('name, lastActivity');
			$query = $this->db->get_where('{CARO_PREFIX}players', array('id' => $player2Id));
			if ($query->num_rows() > 0){
				$player2 = $query->row_array();
				if (strtotime(date("Y-m-d H:i:s")) - strtotime($player2['lastActivity']) > CARO_PLAYER_TIMEOUT){
					$resultArray["player2Id"] = 0;
					$resultArray["player2Name"] = "";
					$result += 2;
					
				}
				else {
					$resultArray["player2Id"] = $player2Id;
					$resultArray["player2Name"] = $player2['name'];
				}
			}
			else {
				$resultArray["player2Id"] = 0;
				$resultArray["player2Name"] = "";
				$result += 2;
			}
			
		}
		else {
			$resultArray["player2Id"] = 0;
			$resultArray["player2Name"] = "";
			$result += 2;
		}
		$this->db->select('name, lastActivity');
		$query = $this->db->get_where('{CARO_PREFIX}players', array('id' => $player1Id));
		if ($query->num_rows() > 0){
			$player1 = $query->row_array();
			if (strtotime(date("Y-m-d H:i:s")) - strtotime($player1['lastActivity']) > CARO_PLAYER_TIMEOUT){
				$resultArray["player1Id"] = $resultArray["player2Id"];
				$resultArray["player1Name"] = $resultArray["player2Name"];
				$resultArray["player2Id"] = 0;
				$resultArray["player2Name"] = "";
				$result += 1;
			}
			else {
				$resultArray["player1Id"] = $player1Id;
				$resultArray["player1Name"] = $player1['name'];
			}
		}
		else {
			$resultArray["player1Id"] = $resultArray["player2Id"];
			$resultArray["player1Name"] = $resultArray["player2Name"];
			$resultArray["player2Id"] = 0;
			$resultArray["player2Name"] = "";
			$result += 1;

		}
			
		if ($result == 1)
			$this->removePlayer($gameId, $player1Id, $player2Id);
		else if ($result == 2 && $player2Id > 0)
			$this->removePlayer($gameId, $player2Id);
		else if ($result == 3){
			$this->deleteGame(array('gameId' => $gameId, 'player1Id' => $player1Id, 'player2Id' => $player2Id));
			return false;
		}
		return $resultArray;
	}

	private function checkPlayerGameExists($playerId){
		$gameData = $this->getGameData('gameId', "player1Id = ".$playerId." OR player2Id = ".$playerId."");
		if ($gameData)
			return intval($gameData['gameId']);
		return 0;
	}

	private function checkEmpty($moves){
		for ($i = 0; $i < 15; $i++){
			for ($j = 0; $j < 15; $j++){
				if ($moves[$i][$j] == 0)
					return array(
						     'x' => $i,
						     'y' => $j
						     );
			}
		}
		return false;
	}

	private function checkWinner($moves){

		$emptyCount = 0;
		for ($i = 0; $i < 15; $i++){
			for ($j = 0; $j < 15; $j++){
				$move = $moves[$i][$j];
				if ($move == 0){
					$emptyCount++;
				}
				else {
					// horizontal
					if ($i < 11){
						if ($moves[$i + 1][$j] == $move && $moves[$i + 2][$j] == $move && $moves[$i + 3][$j] == $move && $moves[$i + 4][$j] == $move){
							if ($i == 0){
								if ($moves[$i + 5][$j] != $move)
									return $move;
							}
							else if ($i == 10){
								if ($moves[$i - 1][$j] != $move)
									return $move;
							}
							else {
								if ($moves[$i - 1][$j] != $move && $moves[$j + 5][$j] != $move){
									if ($moves[$i - 1][$j] == 0 || $moves[$j + 5][$j] == 0)
										return $move;
								}
							}
						}
					}
					// vertical
					if ($j < 11){
						if ($moves[$i][$j + 1] == $move && $moves[$i][$j + 2] == $move && $moves[$i][$j + 3] == $move && $moves[$i][$j + 4] == $move){
							if ($j == 0){
								if ($moves[$i][$j + 5] != $move)
									return $move;
							}
							else if ($j == 10){
								if ($moves[$i][$j - 1] != $move)
									return $move;
							}
							else {
								if ($moves[$i][$j - 1] != $move && $moves[$j][$j + 5] != $move){
									if ($moves[$i][$j - 1] == 0 || $moves[$j][$j + 5] == 0)
										return $move;
								}
							}
						}
					}
					// diagonal 1
					if ($i < 11 && $j < 11){
						if ($moves[$i + 1][$j + 1] == $move && $moves[$i + 2][$j + 2] == $move && $moves[$i + 3][$j + 3] == $move && $moves[$i + 4][$j + 4] == $move){
							if ($i == 0){
								if ($j == 10)
									return $move;
								else if ($moves[$i + 5][$j + 5] != $move)
									return $move;
							}
							else if ($i == 10){
								if ($j == 0)
									return $move;
								else if ($moves[$i - 1][$j - 1] != $move)
									return $move;
							}
							else {
								if ($moves[$i - 1][$j - 1] != $move && $moves[$j + 5][$j + 5] != $move){
									if ($moves[$i - 1][$j - 1] == 0 || $moves[$j + 5][$j + 5] == 0)
										return $move;
								}
							}
						}
					}
					// diagonal 2
					if ($i < 11 && $j > 3){
						if ($moves[$i + 1][$j - 1] == $move && $moves[$i + 2][$j - 2] == $move && $moves[$i + 3][$j - 3] == $move && $moves[$i + 4][$j - 4] == $move){
							if ($i == 0){
								if ($j == 4)
									return $move;
								else if ($moves[$i + 5][$j - 5] != $move)
									return $move;
							}
							else if ($i == 10){
								if ($j == 14)
									return $move;
								else if ($moves[$i - 1][$j + 1] != $move)
									return $move;
							}
							else {
								if ($moves[$i - 1][$j + 1] != $move && $moves[$j + 5][$j - 5] != $move){
									if ($moves[$i - 1][$j + 1] == 0 || $moves[$j + 5][$j - 5] == 0)
										return $move;
								}
							}
						}
					}
				}
			}
		}
		if ($emptyCount == 0)
			return 0;
		return -1;	
	}

	private function updateGameResult($player1Id, $player2Id, $startTime, $winner, $winnerWins, $draws){
		$this->db->insert('{CARO_PREFIX}records', array('player1Id' => $player1Id, 'player2Id' => $player2Id, 'winner' => $winner, 'startTime' => $startTime, 'endTime' => strtotime(date("Y-m-d H:i:s"))));
		if ($winner == 0){
			$this->db->set("draws", "draws + 1", False);
			$this->db->set("played", "played + 1", False);
			$this->db->where("id", $player1Id);
			$this->db->or_where("id", $player2Id);
			$this->db->update('{CARO_PREFIX}players');
			return array(
				     'draws' => $draws + 1
				     );
		}
		else{
			$this->db->set("wins", "wins + 1", False);
			$this->db->where("id", $winner);
			$this->db->update('{CARO_PREFIX}players');
			$this->db->set("played", "played + 1", False);
			$this->db->where("id", $player1Id);
			$this->db->or_where("id", $player2Id);
			$this->db->update('{CARO_PREFIX}players');
			return array(
				     'player'.$winner.'Wins' => $winnerWins + 1
				     );
		}
	}
	
}