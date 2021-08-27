<?php

namespace Gazelle\Manager;

class Collage extends \Gazelle\Base {

    protected const CACHE_DEFAULT_ARTIST = 'collage_default_artist_%d';
    protected const CACHE_DEFAULT_GROUP = 'collage_default_group_%d';

    public function create(\Gazelle\User $user, int $categoryId, string $name, string $description, string $tagList, \Gazelle\Log $logger) {
        $this->db->prepared_query("
            INSERT INTO collages
                   (UserID, CategoryID, Name, Description, TagList)
            VALUES (?,      ?,          ?,    ?,           ?)
            ", $user->id(), $categoryId, trim($name), trim($description), trim($tagList)
        );
        $id = $this->db->inserted_id();
        $logger->general("Collage $id ($name) was created by " . $user->username());
        return new \Gazelle\Collage($id);
    }

    /**
     * Does another collage already have this name (deleted or otherwise)
     *
     * @param string Name of collage to search
     * @return array [ID of other collage, Deleted 0/1] or null if no match
     */
    public function exists(string $name): ?array {
        return $this->db->row("
            SELECT ID, Deleted
            FROM collages
            WHERE Name = ?
            LIMIT 1
            ", trim($name)
        );
    }

    public function findById(int $id): ?\Gazelle\Collage {
        return $this->db->scalar("SELECT ID FROM collages WHERE ID = ?", $id)
            ? new \Gazelle\Collage($id)
            : null;
    }

    public function findByName(string $name): ?\Gazelle\Collage {
        $id = $this->db->scalar("
            SELECT ID FROM collages WHERE Name = ?
            ", $name
        );
        return $id ? new \Gazelle\Collage($id) : null;
    }

    public function recoverById(int $id) {
        $collageId = $this->db->scalar("SELECT ID FROM collages WHERE ID = ?", $id);
        if ($collageId !== null) {
            return $this->recover($collageId);
        }
    }

    public function recoverByName(string $name) {
        $collageId = $this->db->scalar("SELECT ID FROM collages WHERE Name = ?", $name);
        if ($collageId !== null) {
            return $this->recover($collageId);
        }
    }

    protected function recover(int $id) {
        $this->db->prepared_query("
            UPDATE collages SET
                Deleted = '0'
            WHERE ID = ?
            ", $id
        );
        return new \Gazelle\Collage($id);
    }

    public function coverRow(array $group): string {
        $groupId = $group['ID'];
        $ExtendedArtists = $group['ExtendedArtists'];
        $Artists = $group['Artists'];
        $name = '';
        if (!empty($ExtendedArtists[1]) || !empty($ExtendedArtists[4]) || !empty($ExtendedArtists[5])|| !empty($ExtendedArtists[6])) {
            unset($ExtendedArtists[2]);
            unset($ExtendedArtists[3]);
            $name = \Artists::display_artists($ExtendedArtists, false);
        } elseif (count($Artists) > 0) {
            $name = \Artists::display_artists(['1' => $Artists], false);
        }
        $name .= $group['Name'];
        $groupYear = $group['Year'];
        if ($groupYear > 0) {
            $name = "$name [$groupYear]";
        }
        $tags = new \Tags($group['TagList']);

        return $this->twig->render('collage/row.twig', [
            'group_id'   => $groupId,
            'image'      => \ImageTools::process($group['WikiImage'], true),
            'name'       => $name,
            'tags'       => $tags->format(),
            'tags_plain' => implode(', ', $tags->get_tags()),
        ]);
    }

    /**
     * Create a generic collage name for a personal collage.
     * Used for people who lack the privileges create personal collages with arbitrary names
     *
     * @param string name of the user
     * @return string name of the collage
     */
    public function personalCollageName(string $name): string {
        $new = $name . "'s personal collage";
        $this->db->prepared_query('
            SELECT ID FROM collages WHERE Name = ?
            ', $new
        );
        $i = 1;
        $basename = $new;
        while ($this->db->has_results()) {
            $new = "$basename no. " . ++$i;
            $this->db->prepared_query('
                SELECT ID FROM collages WHERE Name = ?
                ', $new
            );
        }
        return $new;
    }

    protected function idsToNames(array $idList): array {
        if (empty($idList)) {
            return [];
        }
        $this->db->prepared_query("
            SELECT c.ID AS id,
                c.Name AS name
            FROM collages c
            WHERE c.ID IN (" . placeholders($idList) . ")
            ORDER BY c.Updated DESC
            ", ...$idList
        );
        return $this->db->to_pair('id', 'name', false);
    }

    public function addToArtistCollageDefault(int $userId, int $artistId): array {
        $key = sprintf(self::CACHE_DEFAULT_ARTIST, $userId);
        $default = $this->cache->get_value($key);
        if ($default === false) {
            // Ensure that some of the creator's collages are in the result
            $this->db->prepared_query("
                SELECT c.ID
                FROM collages c
                WHERE c.Locked = '0'
                    AND c.Deleted = '0'
                    AND c.UserID = ?
                    AND c.CategoryID = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM collages_artists WHERE CollageID = c.ID AND ArtistID = ?
                    )
                ORDER BY c.Updated DESC
                LIMIT 5
                ", $userId, COLLAGE_ARTISTS_ID, $artistId
            );
            $list = $this->db->collect(0, false);
            if (empty($list)) {
                // Prevent empty IN operator: WHERE ID IN ()
                $list = [0];
            }

            // Ensure that some of the other collages the user has worked on are present
            $this->db->prepared_query("
                SELECT c.ID
                FROM collages c
                INNER JOIN collages_artists ca ON (ca.CollageID = c.ID AND ca.UserID = ?)
                WHERE c.Locked = '0'
                    AND c.Deleted = '0'
                    AND c.UserID != ?
                    AND c.CategoryID = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM collages_artists WHERE CollageID = c.ID AND ArtistID = ?
                    )
                GROUP BY c.ID
                ORDER BY max(ca.AddedOn) DESC
                LIMIT 5
                ", $userId, $userId, COLLAGE_ARTISTS_ID, $artistId
            );
            $default = $this->idsToNames(array_merge($list, $this->db->collect(0)));
            $this->cache->cache_value($key, $default, 86400);
        }
        return $default;
    }

    public function addToCollageDefault(int $userId, int $groupId): array {
        $key = sprintf(self::CACHE_DEFAULT_GROUP, $userId);
        $default = $this->cache->get_value($key);
        if ($default === false) {
            // All of their personal collages are in the result
            $this->db->prepared_query("
                SELECT c.ID
                FROM collages c
                WHERE c.Locked = '0'
                    AND c.Deleted = '0'
                    AND c.UserID = ?
                    AND c.CategoryID = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM collages_torrents WHERE CollageID = c.ID AND GroupID = ?
                    )
                ORDER BY c.Updated DESC
                ", $userId, COLLAGE_PERSONAL_ID, $groupId
            );
            $list = $this->db->collect(0, false) ?: [0];

            // Ensure that some (theirs and by others) of the other collages the user has worked on are present
            $this->db->prepared_query("
                SELECT c.ID
                FROM collages c
                INNER JOIN collages_torrents ca ON (ca.CollageID = c.ID AND ca.UserID = ?)
                WHERE c.Locked = '0'
                    AND c.Deleted = '0'
                    AND c.CategoryID != ?
                    AND NOT EXISTS (
                        SELECT 1 FROM collages_torrents WHERE CollageID = c.ID AND GroupID = ?
                    )
                GROUP BY c.ID
                ORDER BY max(ca.AddedOn) DESC
                LIMIT 5
                ", $userId, COLLAGE_PERSONAL_ID, $groupId
            );
            unset($list[0]);
            $default = $this->idsToNames(array_merge($list, $this->db->collect(0)));
            $this->cache->cache_value($key, $default, 86400);
        }
        return $default;
    }

    public function flushDefaultArtist(int $userId) {
        $this->cache->delete_value(sprintf(self::CACHE_DEFAULT_ARTIST, $userId));
    }

    public function flushDefaultGroup(int $userId) {
        $this->cache->delete_value(sprintf(self::CACHE_DEFAULT_GROUP, $userId));
    }

    public function autocomplete(string $text): array {
        $maxLength = 10;
        $length = min($maxLength, max(1, mb_strlen($text)));
        if ($length < 3) {
            return [];
        }
        $stem = mb_strtolower(mb_substr($text, 0, $length));
        $key = 'autocomplete_collage_' . $length . '_' . $stem;
        if (($autocomplete = $this->cache->get($key)) === false) {
            $this->db->prepared_query("
                SELECT ID,
                    Name
                FROM collages
                WHERE Locked = '0'
                    AND Deleted = '0'
                    AND CategoryID NOT IN (?, ?)
                    AND lower(Name) LIKE concat('%', ?, '%')
                ORDER BY NumTorrents DESC, Name
                LIMIT 10
                ", COLLAGE_ARTISTS_ID, COLLAGE_PERSONAL_ID, $stem
            );
            $pairs = $this->db->to_pair('ID', 'Name', false);
            $autocomplete = [];
            foreach($pairs as $key => $value) {
                $autocomplete[] = ['data' => $key, 'value' => $value];
            }
            $this->cache->cache_value($key, $autocomplete, 1800 + 7200 * ($maxLength - $length));
        }
        return $autocomplete;
    }

    public function subscribedGroupCollageList(int $userId, bool $showRecent): array {
        $cond = ['s.UserID = ?'];
        $args = [$userId];
        if ($showRecent) {
            $cond[] = 'ct.AddedOn > s.LastVisit';
            $groupIds = 'group_concat(ct.GroupID ORDER BY ct.AddedOn)';
        } else {
            $groupIds = 'group_concat(if(ct.AddedOn > s.LastVisit, ct.GroupID, NULL) ORDER BY ct.AddedOn)';
        }
        $this->db->prepared_query("
            SELECT c.ID       AS collageId,
                c.Name        AS name,
                c.NumTorrents AS nrEntries,
                s.LastVisit   AS lastVisit,
                $groupIds     AS groupIds
            FROM collages AS c
            INNER JOIN users_collage_subs AS s ON (s.CollageID = c.ID)
            INNER JOIN collages_torrents AS ct ON (ct.CollageID = c.ID)
            WHERE c.Deleted = '0'
                AND " . implode(' AND ', $cond) . "
            GROUP BY c.ID
            ", ...$args
        );
        $list = $this->db->to_array(false, MYSQLI_ASSOC, false);
        foreach ($list as &$entry) {
            $entry['groupIds'] = is_null($entry['groupIds']) ? [] : explode(',', $entry['groupIds']);
        }
        return $list;
    }

    public function subscribedArtistCollageList(int $userId, bool $showRecent): array {
        $cond = ['s.UserID = ?'];
        $args = [$userId];
        if ($showRecent) {
            $cond[] = 'ca.AddedOn > s.LastVisit';
            $artistIds = 'group_concat(ca.ArtistID ORDER BY ca.AddedOn)';
        } else {
            $artistIds = 'group_concat(if(ca.AddedOn > s.LastVisit, ca.ArtistID, NULL) ORDER BY ca.AddedOn)';
        }
        $this->db->prepared_query("
            SELECT c.ID       AS collageId,
                c.Name        AS name,
                c.NumTorrents AS nrEntries,
                s.LastVisit   AS lastVisit,
                $artistIds    AS artistIds
            FROM collages AS c
            INNER JOIN users_collage_subs AS s ON (s.CollageID = c.ID)
            INNER JOIN collages_artists AS ca ON (ca.CollageID = c.ID)
            WHERE c.Deleted = '0'
                AND " . implode(' AND ', $cond) . "
            GROUP BY c.ID
            ", ...$args
        );
        $list = $this->db->to_array(false, MYSQLI_ASSOC, false);
        foreach ($list as &$entry) {
            $entry['artistIds'] = is_null($entry['artistIds']) ? [] : explode(',', $entry['artistIds']);
        }
        return $list;
    }
}
