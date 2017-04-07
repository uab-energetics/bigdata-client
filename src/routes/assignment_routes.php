<?php
// set up some aliases for less typing later
use ArangoDBClient\Collection as ArangoCollection;
use ArangoDBClient\CollectionHandler as ArangoCollectionHandler;
use ArangoDBClient\Connection as ArangoConnection;
use ArangoDBClient\ConnectionOptions as ArangoConnectionOptions;
use ArangoDBClient\DocumentHandler as ArangoDocumentHandler;
use ArangoDBClient\EdgeHandler as ArangoEdgeHandler;
use ArangoDBClient\Document as ArangoDocument;
use ArangoDBClient\Edge as ArangoEdge;
use ArangoDBClient\Exception as ArangoException;
use ArangoDBClient\Export as ArangoExport;
use ArangoDBClient\ConnectException as ArangoConnectException;
use ArangoDBClient\ClientException as ArangoClientException;
use ArangoDBClient\ServerException as ArangoServerException;
use ArangoDBClient\Statement as ArangoStatement;
use ArangoDBClient\UpdatePolicy as ArangoUpdatePolicy;

/**
 * GET assignmentsIDGet
 * Summary: Returns a single assignment
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/assignments/{ID}', function ($request, $response, $args) {
    $ID = $args['ID'];
    if (!$this->arangodb_documentHandler->has("assignments", $ID)) {
        return $response
            ->write("No assignment found");
    }
    $assignment = $this->arangodb_documentHandler->get("assignments", $ID)->getAll();
    return $response->write(json_encode($assignment, JSON_PRETTY_PRINT));
});

/**
 * PUT assignmentsIDPut
 * Summary: Updates assignment with a students work
 * Notes:
 */
$app->PUT('/assignments/{ID}', function ($request, $response, $args) {
    $formData = $request->getParams();
    /* Validate request */
    if (
        !isset($formData['encoding']) ||
        !isset($formData['done']) ||
        !isset($formData['completion'])
    ) {
        return $response
            ->write("Bad Request")
            ->withStatus(400);
    }
    // TODO - validate encoding integrity before insert
    /* Make sure assignment exists */
    if (!$this->arangodb_documentHandler->has("assignments", $args["ID"])) {
        echo "That assignment does not exist";
        return;
    }
    /* Update Document */

    $encoding = json_decode($formData['encoding'], true);

    $assignment = $this->arangodb_documentHandler->get("assignments", $args["ID"]);
    $assignment->set("done", $formData['done']);
    $assignment->set("completion", $formData['completion']);
    $assignment->encoding = $encoding;
    $result = $this->arangodb_documentHandler->update($assignment);

    if ($result) {
        return $response
            ->write("Updated Assignment " . $args['ID'])
            ->withStatus(200);
    } else {
        return $response
            ->write("Could not update assignment")
            ->withStatus(500);
    }
});

/**
 * GET studentsIDAssignmentsGet
 * Summary: Returns a list of assignments to a student
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/users/{ID}/assignments', function ($request, $response, $args) {
    $userID = $args["ID"];
    // make sure student exists
    if (!$this->arangodb_documentHandler->has('users', $userID)) {
        return $response->write("No student with that ID found")
            ->withStatus(400);
    }
    $statement = new ArangoStatement(
        $this->arangodb_connection, [
            'query' => 'FOR assignment IN INBOUND CONCAT("users/", @userID) assigned_to
                            FOR paper IN OUTBOUND assignment._id assignment_of
                                RETURN MERGE(assignment, {title: paper.title, pmcID: paper._key})',
            'bindVars' => [
                'userID' => $userID
            ],
            '_flat' => true
        ]
    );
    $resultSet = $statement->execute()->getAll();
    return $response->write(json_encode($resultSet, JSON_PRETTY_PRINT));
});

/**
 * POST studentsIDAssignmentsPost
 * Summary: Creates an assignment to a student
 * Notes:
 */
$app->POST('/users/{ID}/assignments', function ($request, $response, $args) {
    $userID = $args['ID'];
    $pmcID = $request->getParam("pmcID");

    // Make sure student exists
    if (!$this->arangodb_documentHandler->has("users", $userID)) {
        return $response
            ->write("No user with that ID")
            ->withStatus(400);
    }
    // Make sure paper exists
    if (!$this->arangodb_documentHandler->has("papers", $pmcID)) {
        return $response
            ->write("No paper with that ID")
            ->withStatus(400);
    }
    // Make sure the assignment doesn't exist already
    $statement = new ArangoStatement(
        $this->arangodb_connection, [
            'query' => 'FOR assignment IN INBOUND CONCAT("users/", @userID) assigned_to
                            FOR paper IN OUTBOUND assignment._id assignment_of
                                FILTER paper._key == @pmcID
                                RETURN 1',
            'bindVars' => [
                'userID' => $userID,
                'pmcID' => $pmcID
            ],
            '_flat' => true
        ]
    );
    if (count($statement->execute()->getAll()) > 0) {
        return $response
            ->write("Duplicate Assignment")
            ->withStatus(400);
    }

    // Create the assignment
    $assignmentObject = ArangoDocument::createFromArray([
        "done" => false,
        "completion" => 0,
        "encoding" => null
    ]);
    $assignmentID = $this->arangodb_documentHandler->save("assignments", $assignmentObject);

    // Create the assignment_of edge
    $assignment_of = ArangoDocument::createFromArray([
        "_to" => "papers/" . $pmcID,
        "_from" => $assignmentID
    ]);
    $assignment_of_result = $this->arangodb_documentHandler->save("assignment_of", $assignment_of);

    // Create the assigned_to edge
    $assigned_to = ArangoDocument::createFromArray([
        "_to" => "users/" . $userID,
        "_from" => $assignmentID
    ]);
    $assigned_to_result = $this->arangodb_documentHandler->save("assigned_to", $assigned_to);

    // get the new assignment and return it
    if ($assignmentID && $assignment_of_result && $assigned_to_result) {
        return $response
            ->write(json_encode([
                "msg" => "Assignment created successfully",
                "userID" => $userID,
                "assignmentID" => $assignmentID
            ], JSON_PRETTY_PRINT));
    } else {
        return $response
            ->write("Something went wrong :(")
            ->withStatus(500);
    }
});


/** POST studies/{studyname}/papers
 *  Add a new paper to the database
 */
$app->POST("/studies/{studyname}/papers", function ($request, $response, $args) {
    $studyName = $args['studyname'];
    $formData = $request->getParams();

    //Check to make sure that the research study exists
    if (!$this->arangodb_documentHandler->has("research_studies", $studyName)) {
        return $response->write("No research study with name " . $studyName . " found")
            ->withStatus(400);
    }

    //check if we have all form data
    if (!isset($formData['pmcID']) || !isset($formData['title'])) {
        return $response->write("Please include 'pmcID' and 'title' parameters in the post request")
            ->withStatus(400);
    }

    if ($this->arangodb_documentHandler->has("papers", $formData['pmcID'])) {
        return $response->write("A paper with pmcID " . $formData['pmcID'] . " already exists")
            ->withStatus(409);
    }

    //Create the paper document
    $paper = new ArangoDocument();
    $paper->set("_key", $formData['pmcID']);
    $paper->set("title", $formData['title']);
    $paperID = $this->arangodb_documentHandler->save("papers", $paper);

    if (!$paperID) {
        return $response->write("Something went wrong when saving the paper")
            ->withStatus(500);
    }

    //Create the edge from the new paper to the research study
    $edge = new ArangoDocument();
    $edge->set("_from", "papers/" . $formData['pmcID']);
    $edge->set("_to", "research_studies/" . $studyName);
    $edgeID = $this->arangodb_documentHandler->save("paper_of", $edge);

    if (!$edgeID) {
        return $response
            ->write("Something went wrong when assigning the paper to the research study");
    }

    return $response
        ->write("Successfully added paper " . $formData['pmcID'] . " to research study " . $studyName);
});