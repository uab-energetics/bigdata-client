<?php
// set up some aliases for less typing later
use ArangoDBClient\Collection as ArangoCollection;
use ArangoDBClient\CollectionHandler as ArangoCollectionHandler;
use ArangoDBClient\Connection as ArangoConnection;
use ArangoDBClient\ConnectionOptions as ArangoConnectionOptions;
use ArangoDBClient\DocumentHandler as ArangoDocumentHandler;
use ArangoDBClient\Document as ArangoDocument;
use ArangoDBClient\Exception as ArangoException;
use ArangoDBClient\Export as ArangoExport;
use ArangoDBClient\ConnectException as ArangoConnectException;
use ArangoDBClient\ClientException as ArangoClientException;
use ArangoDBClient\ServerException as ArangoServerException;
use ArangoDBClient\Statement as ArangoStatement;
use ArangoDBClient\UpdatePolicy as ArangoUpdatePolicy;

// Routes

// This is a control test route to make sure the server is running properly
$app->get('/hello/{name}', function ($request, $response, $args) {
    $response->getBody()->write($args['name']);
});

// This route is authenticated using middleware
$app->get('/secure', function ($request, $response, $args) {
    echo "You are authorized";
    return;
});

$app->POST("/papers", function($request, $response){
    $formData = $request->getParams();

    $paper = new ArangoDocument();
    $paper->set("_key", $formData['pmcID']);
    $paper->set("title", $formData['title']);
    $ID = $this->arangodb_documentHandler->save("papers", $paper);

    // get the new assignment and return it
    if($ID){
        $res = [
            "status" => "OK",
            "assignment" => $this->arangodb_documentHandler->get("assignedTo", $ID)->getAll()
        ];
    } else {
        $res = [
            "status" => "ERROR",
            "msg" => "Something went wrong... :("
        ];
    }
    return $response->write(json_encode($res, JSON_PRETTY_PRINT));
});

/**
 * DELETE assignmentsIDDelete
 * Summary: Deletes an assignment
 * Notes:
 */
$app->DELETE('/assignments/{ID}', function ($request, $response, $args) {
    $response->write('How about implementing assignmentsIDDelete as a DELETE method ?');
    return $response;
});


/**
 * GET assignmentsIDGet
 * Summary: Returns a single assignment
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/assignments/{ID}', function ($request, $response, $args) {
    $ID = $args['ID'];
    if(!$this->arangodb_documentHandler->has("assignedTo", $ID)){
        echo "No assignment found";
        return;
    }
    $assignment = $this->arangodb_documentHandler->get("assignedTo", $ID)->getAll();
    return $response->write(json_encode($assignment, JSON_PRETTY_PRINT));
});

/**
 * PUT assignmentsIDPut
 * Summary: Updates assignment with a students work
 * Notes:
 */
$app->PUT('/assignments/{ID}', function ($request, $response, $args) {
    // Make sure assignment exists
    if(!$this->arangodb_documentHandler->has("assignedTo", $args["ID"])){
        echo "That assignment does not exist";
        return;
    }

    $formData = $request->getParams();
    $assignment = $this->arangodb_documentHandler->get("assignedTo", $args["ID"]);
    $assignment->set("done", $formData['done']);
    $assignment->set("completion", $formData['completion']);
    $assignment->set("encoding", $formData['encoding']);
    $result = $this->arangodb_documentHandler->update($assignment);

    if($result){
        $res = [
            "status" => "OK",
            "assignment" => $assignment->getAll(),
        ];
        return $response->write(json_encode($res, JSON_PRETTY_PRINT))
            ->withStatus(200);
    } else {
        $res = [
            "status" => "ERROR",
            "msg" => "Could not update assignment",
        ];
        return $response->write(json_encode($res, JSON_PRETTY_PRINT))
            ->withStatus(401);
    }
});

/**
 * GET studentsIDAssignmentsGet
 * Summary: Returns a list of assignments to a student
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/students/{ID}/assignments', function ($request, $response, $args) {
    $studentID = $args["ID"];
    // make sure student exists
    if (!$this->arangodb_documentHandler->has('users', $studentID)) {
        $res = [
            'status' => "ERROR",
            'msg' => "No student with that ID found"
        ];
        return $response->write(json_encode($res, JSON_PRETTY_PRINT));
    }
    $statement = new ArangoStatement(
        $this->arangodb_connection, [
            'query' => 'FOR assignment IN INBOUND CONCAT("users/", @studentID) assignedTo RETURN assignment',
            'bindVars' => [
                'studentID' => $studentID
            ],
            '_flat' => true
        ]
    );
    $resultSet = $statement->execute()->getAll();
    $response->write(json_encode($resultSet, JSON_PRETTY_PRINT));
    return $response;
});

/**
 * POST studentsIDAssignmentsPost
 * Summary: Creates an assignment to a student
 * Notes:
 */
$app->POST('/students/{ID}/assignments', function ($request, $response, $args) {
    $studentID = $args['ID'];
    $paperID = $request->getParam("pmcID");

    // Make sure student exists
    if(!$this->arangodb_documentHandler->has("users", $studentID)){
        echo "No student with that ID";
        return;
    }
    // Make sure paper exists
    if(!$this->arangodb_documentHandler->has("papers", $paperID)){
        echo "No paper with that ID";
        return;
    }

    // Create the assignment object
    $assignmentEdge = new ArangoDocument();
    $assignmentEdge->set("_to", "users/".$studentID);
    $assignmentEdge->set("_from", "papers/".$paperID);
    $assignmentEdge->set("done", false);
    $assignmentEdge->set("completion", 0);
    $assignmentEdge->set("encoding", null);
    $newAssignmentID = $this->arangodb_documentHandler->save("assignedTo", $assignmentEdge);

    // get the new assignment and return it
    if($newAssignmentID){
        $res = [
            "status" => "OK",
            "assignment" => $this->arangodb_documentHandler->get("assignedTo", $newAssignmentID)->getAll()
        ];
    } else {
        $res = [
            "status" => "ERROR",
            "msg" => "Something went wrong... :("
        ];
    }
    return $response->write(json_encode($res, JSON_PRETTY_PRINT));
});


/**
 * GET classesIDStudentsGet
 * Summary: Returns a list of students in a class
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/classes/{ID}/students', function($request, $response, $args) {
    $classID = $args["ID"];
    $collectionHandler = new ArangoCollectionHandler($this->arangodb_connection);

    //Make sure the class exists
    if (!$collectionHandler.has('classes', $classID)) {
        $res = [
            'status' => "INVALID",
            'msg' => "No class with ID ".$classID." exists"
        ];
    } //The class exists, proceed
    else {
        $statement = new ArangoStatement(
            $this->arangodb_connection, [
                'query' => 'FOR student IN INBOUND CONCAT("classes/", @classID) enrolledIn RETURN student._key',
                'bindVars' => [
                    'studentID' => $classID
                ],
                '_flat' => true
            ]
        );
        $res = $statement->execute()->getAll();
    }

    $response->write(json_encode($res,JSON_PRETTY_PRINT));
    return $response;
});


/**
 * POST classesIDStudentsPost
 * Summary: Enrolls a student in a class
 * Notes:
 * Output-Formats: [application/json]
 */
$app->POST('/classes/{ID}/students', function($request, $response, $args) {
    $classID = $args["ID"];
    $studentID = $request->getParam("studentID");
    $collectionHandler = new ArangoCollectionHandler ($this->arangodb_connection);

    //Make sure the class exists
    if (!$collectionHandler->has('classes', $classID)) {
        $res = [
            'status' => "INVALID",
            'msg' => "No class with ID ".$classID." exists."
        ];

    } //Make sure the student exists
    else if (!$collectionHandler->has('users', $studentID)) {
        $res = [
            'status' => "INVALID",
            'msg' => "No student with ID ".$studentID." exists."
        ];

    } //Make sure the student isn't already enrolled
    else if ($collectionHandler->getByExample('enrolledIn', ['_from' => $studentID, '_to' => $classID])->getCount() > 0) {
        $res = [
            'status' => "DUPLICATE",
            'msg' => "Student ".$studentID." is already enrolled in class ".$classID
        ];

    else {
        $documentHandler = new ArangoDocumentHandler($this->arangodb_connection);
        $edge = new ArangoDocument();
        $edge->set('_from', $studentID);
        $edge->set('_to', $classID);
        $documentHandler->save('enrolledIn', edge);
        $res = [
            'status' => "OK",
            'msg' => "Successfully enrolled student ".$studentID." into class ".$classID
        ];
    }

    $response->write (json_encode($res, JSON_PRETTY_PRINT));
    return $response;
});


/**
 * GET studentIDClassesGet
 * Summary:
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/student/{ID}/classes', function($request, $response, $args) {
    $studentID = $args["ID"];

    $docHandler = new ArangoDocumentHandler($this->arangodb_connection);
    if (!$docHandler->has('users', $studentID)) {
        $res = [
            'status' => "ERROR",
            'msg' => "No student with that ID found"
        ];
    } else { //The student exists
        $statement = new ArangoStatement(
            $this->arangodb_connection, [
                'query' => 'FOR class IN OUTBOUND CONCAT("users/", @studentID) teaches RETURN class',
                'bindVars' => [
                    'studentID' => $studentID
                ],
                '_flat' => true
            ]
        );
        $res = $statement->execute()->getAll();
    }

    $response->write(json_encode ($res, JSON_PRETTY_PRINT));
    return $response;
});


/**
 * GET teacherIDClassesGet
 * Summary: Returns a list of a teacher&#39;s classes
 * Notes:
 * Output-Formats: [application/json]
 */
$app->GET('/teacher/{ID}/classes', function($request, $response, $args) {
    $teacherID = $args["ID"];

    $docHandler = new ArangoDocumentHandler($this->arangodb_connection);
    if (!$docHandler->has('users', $teacherID)) {
        $res = [
            'status' => "ERROR",
            'msg' => "No student with that ID found"
        ];
    } else { //The student exists
        $statement = new ArangoStatement(
            $this->arangodb_connection, [
                'query' => 'FOR class IN OUTBOUND CONCAT("users/", @teacherID) teaches RETURN class',
                'bindVars' => [
                    'teacherID' => $teacherID
                ],
                '_flat' => true
            ]
        );
        $res = $statement->execute()->getAll();
    }

    $response->write(json_encode ($res, JSON_PRETTY_PRINT));
    return $response;
});

/**
 * POST teacherIDClassesPost
 * Summary: Creates a class under a teaher
 * Notes:
 */
$app->POST('/teacher/{ID}/classes', function($request, $response, $args) {
    $teacherID = args["ID"];

    //Make sure teacher exists

    $className = $request->getParsedBody()->name;
    $documentHandler = new ArangoDocumentHandler();

    $class = new ArangoDocument();
    $class.set("name", $className);
    $classID = $documentHandler->save($class, "classes");

    $edge = new ArangoDocument ();
    $edge->set ("_from", "users/".$teacherID);
    $edge->set ("_to", "classes/".$classID);

    return $response;
});


/**
 * POST usersLoginPost
 * Summary: Logs in user
 * Notes:
 * Output-Formats: [application/json]
 */
$app->POST('/users/login', function ($request, $response, $args) {
    $email = $request->getParam("email");
    $password = $request->getParam("password");
    $collectionHandler = new ArangoCollectionHandler($this->arangodb_connection);
    $cursor = $collectionHandler->byExample('users', ['email' => $email, 'password' => $password]);

    if ($cursor->getCount() == 0) {
        $ResponseToken = [
            "status" => "INVALID",
            "msg" => "No account with that email and password in the database"
        ];
        return $response
            ->write(json_encode($ResponseToken, JSON_PRETTY_PRINT))
            ->withStatus(401);
    }

    $user = $cursor->current()->getAll();
    $userDetails = [
        "ID" => $user["_key"],
        "name" => $user['name'],
        "email" => $user['email'],
        "roles" => $user['roles']
    ];

    // Building the JWT
    $tokenId = base64_encode(mcrypt_create_iv(32));
    $issuedAt = time();
    $expire = $issuedAt + 60 * 30;            // Adding 60 seconds
    $data = [
        'iat' => $issuedAt,         // Issued at: time when the token was generated
        'jti' => $tokenId,          // Json Token Id: an unique identifier for the token
        'iss' => "Big Data",       // Issuer
        'exp' => $expire,           // Expire
        'data' => [                  // Data related to the signer user
            'userId' => $user["_key"], // userid from the users table
            'userEmail' => $user["name"], // User name
        ]
    ];
    $token = $this->JWT->encode($data, $this->get("settings")['JWT_secret']);

    $ResponseToken = [
        "status" => "OK",
        "token" => $token,
        "user" => $userDetails
    ];
    return $response->write(json_encode($ResponseToken, JSON_PRETTY_PRINT));
});

/**
 * POST usersRegisterPost
 * Summary: Registers user
 * Notes:
 * Output-Formats: [application/json]
 */
$app->POST('/users/register', function ($request, $response, $args) {
    $collectionHandler = new ArangoCollectionHandler($this->arangodb_connection);
    $documentHandler = new ArangoDocumentHandler($this->arangodb_connection);
    $formData = $request->getParams();
    // Validate role input
    if (!in_array($formData['role'], User::roles)) {
        echo "What are you trying to pull?";
        return;
    }
    // Make sure account with email does not already exist
    if ($collectionHandler->byExample('users', ['email' => $formData['email']])->getCount() > 0) {
        $res = [
            "status" => "EXIST",
            "msg" => "An account with that email already exists"
        ];
        return $response->write(json_encode($res, JSON_PRETTY_PRINT));
    }
    // create a new document
    $user = new ArangoDocument();
    // use set method to set document properties
    $user->set('name', $formData['name']);
    $user->set('email', $formData['email']);
    $user->set('password', $formData['password']);
    $user->set('date_created', date("Y-m-d"));
    $user->set('role', $formData['role']);
    // Insert user into the DB
    $id = $documentHandler->save('users', $user);
    // check if a document exists
    $result = $documentHandler->has('users', $id);
    if ($result == true) {
        $res = [
            "status" => "OK",
            "user" => $documentHandler->get("users", $id)->getAll()
        ];
    } else {
        $res = [
            "status" => "ERROR",
            "msg" => "Something went wrong"
        ];
    }
    return $response->write(json_encode($res, JSON_PRETTY_PRINT));
});
