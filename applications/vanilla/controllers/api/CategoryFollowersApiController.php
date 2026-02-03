<?php
/**
 * @author Nathan Atkinson <natkinson@instructure.com>
 * @copyright 2025 Instructure, Inc.
 * @license GPL-2.0-only
 */

use Garden\Web\Data;
use Garden\Web\Exception\NotFoundException;
use Garden\Schema\ValidationException;

/**
 * API Controller for the `/category-followers` resource.
 * 
 * Provides endpoints to retrieve users following specific categories.
 */
class CategoryFollowersApiController extends AbstractApiController
{
    /**
     * Get a list of users following a specific category.
     *
     * @param int $id The target category's ID.
     * @param array $query Query parameters for pagination and filtering.
     * @return Data
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function get_index(int $id, array $query): Data
    {
        $this->permission();

        // Validate the category exists
        $category = CategoryModel::categories($id);
        if (empty($category)) {
            throw new NotFoundException("Category");
        }

        // Schema for query parameters
        $in = $this->schema([
            "page:i?" => [
                "description" => "Page number. See Pagination.",
                "default" => 1,
                "minimum" => 1,
            ],
            "limit:i?" => [
                "description" => "Desired number of items per page. Use -1 to get all followers.",
                "default" => 30,
                "minimum" => -1,
            ],
        ]);
        $query = $in->validate($query);

        // Schema for output - user information joined from UserCategory
        $out = $this->schema([
            ":a" => [
                "items" => [
                    "type" => "object",
                    "properties" => [
                        "userID" => ["type" => "integer"],
                        "name" => ["type" => "string"],
                        "email" => ["type" => "string"],
                        "role" => ["type" => "string"],
                        "dateFollowed" => ["type" => "string"],
                    ],
                ],
            ],
        ]);

        $sql = Gdn::sql();
        $sql->reset();

        // Build the query to get followers of the category
        $query_builder = $sql
            ->select("u.UserID, u.Name, u.Email, r.Name as Role, uc.DateInserted as DateFollowed")
            ->from("UserCategory uc")
            ->join("User u", "uc.UserID = u.UserID")
            ->leftJoin("Role r", "u.RoleID = r.RoleID")
            ->where([
                "uc.CategoryID" => $id,
                "uc.Followed" => 1,
            ])
            ->orderBy("uc.DateInserted", "DESC");

        // Handle limit of -1 for all results
        $limit = $query["limit"];
        if ($limit === -1) {
            // Get all followers without pagination
            $followers = $query_builder->get()->resultArray();
        } else {
            // Apply pagination
            $page = $query["page"];
            [$offset, $limit] = offsetLimit("p{$page}", $limit);
            $followers = $query_builder
                ->limit($limit, $offset)
                ->get()
                ->resultArray();
        }

        // Normalize the output
        $result = array_map(function ($follower) {
            return [
                "userID" => (int) $follower["UserID"],
                "name" => $follower["Name"] ?? "",
                "email" => $follower["Email"] ?? "",
                "role" => $follower["Role"] ?? null,
                "dateFollowed" => $follower["DateFollowed"] ?? "",
            ];
        }, $followers);

        $out->validate($result);

        return new Data($result);
    }
}
