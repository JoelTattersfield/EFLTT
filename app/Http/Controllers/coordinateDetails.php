<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

class coordinateDetails extends Controller
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    // General thoughts
    //
    // There's a lot happening in this controller that probably shouldn't be, mostly the gmaps, S3 and W3W stuff should probably live in service providers
    // With that in mind I've left some comments of my thoughts to help indicate what it is I'm thinking about when I work as "probably" isn't "definitely" and it would really depend on the purpose of a lot of it exactly where it gets abstracted to
    
    public function __invoke(Request $request)
    {
        $start = microtime(true);
        $address = $request->input('query');
        
        // Can gmaps respond multiple results? Take first or error? Loop the results and return all? Wider hypothetical project would inform this
        // Generic name and leaving the endpoint response intact because I'm assuming hitting this endpoint would be usefull more than just in this case
        $location = $this->getGeocode($address)->results[0]->geometry->location;

        $coordinates = [
            'latitude' => $location->lat,
            'longitude' => $location->lng
        ];
        // Abstracted for testing. Should it live in this file? Consider usefulness elsewhere in the hypothetical project
        $antipode = $this->convertCoordsToAntipode($coordinates['latitude'], $coordinates['longitude']);

        // repetition of ->results[0]->elevation, better to leave endpoint results intact inside the function however, presuming it's potentially useful outside this controller
        // Same question of multiple results?
        $coordinates['elevation'] = $this->getCoordinatesElevation($coordinates['latitude'], $coordinates['longitude'])->results[0]->elevation;
        $antipode['elevation'] = $this->getCoordinatesElevation($antipode['latitude'], $antipode['longitude'])->results[0]->elevation;
    
        // same again, assume reusable, leave the response intact and pick out what you need in the instance you need it, don't limit reuse by ditching data in the function definition
        $coordinates['what_three_words'] = $this->getWhatThreeWordsCode($coordinates['latitude'], $coordinates['longitude'])->words;
        $antipode['what_three_words'] = $this->getWhatThreeWordsCode($antipode['latitude'], $antipode['longitude'])->words;

        // Useful elsewhere? If so, definitely middleware territory
        $execution_time = (microtime(true) - $start) * 1000;

        $responseBody = [
            'originalQuery' => $address,
            'coordinates' => $coordinates,
            'antipode' => $antipode,
            'execution_time' => "$execution_time ms"
        ];

        $response = response()->json($responseBody);

        $this->uploadJsonToS3(json_encode($responseBody));
        return $response;
    }

    // Shouldn't really live here, most likely better off in a google maps api service provider, assuming there will be several more instances of accessing the gmaps API in the project
    private function getGeocode(String $address)
    {        
        $gMapResponse = $this->client->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
            'query' => [
                'address' => urlencode($address),
                'key' => env('GOOGLE_MAPS_API_KEY')
            ]
        ]);
    
        return json_decode($gMapResponse->getBody()->getContents());
    }

    // As above, maybe a W3W service provider? Depends on how complex it's likely to get really, one to consider current requirements as well as future when making the decision
    // Question around should we take an object or two args like this, personally I don't mind a small amount of repetition in code ($coordinates['latitude'], $coordinates['longitude']) if it helps readability
    private function getWhatThreeWordsCode(float $lat, float $lng)
    {
        $w3WResponse = $this->client->request('GET', 'https://api.what3words.com/v3/convert-to-3wa', [
            'query' => [
                'coordinates' => $lat.','.$lng,
                'key' => env('WHAT_THREE_WORDS_API_KEY')
            ]
        ]);
    
        return json_decode($w3WResponse->getBody()->getContents());
    }

    private function getCoordinatesElevation(float $lat, float $lng)
    {    
        $gMapElevationResponse = $this->client->request('GET', 'https://maps.googleapis.com/maps/api/elevation/json', [
            'query' => [
                'locations' => "$lat,$lng",
                'key' => env('GOOGLE_MAPS_API_KEY')
            ]
        ]);
    
        return json_decode($gMapElevationResponse->getBody()->getContents());
    }

    private function convertCoordsToAntipode(float $lat, float $lng)
    {
        return [
            'latitude' => -$lat,
            'longitude' => $lng > 0 ? $lng - 180 : $lng + 180,
        ];
    }

    // definitely doesn't belong in this controller, as above the exact solution depends on the wider project somewhat, but a service provider seems like a good shout
    // quite specifically named too, depending on project requirements a good idea might be to create some file storage interface that then points to the S3 service provider, make it easier to ditch S3 later
    private function uploadJsonToS3(string $json)
    {
        // Creating a file in memory, no need to keep it ourselves so storing on the disk is wasteful
        $fileForS3 = fopen('php://memory', 'w+');
        fwrite($fileForS3, $json);
        rewind($fileForS3);
        
        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);

        try {
            $result = $s3->putObject([
                'Bucket' => env('AWS_BUCKET'),
                // Might be an idea to set this to the time of the request for help matching it to logs, define('LARAVEL_START', microtime(true)); in the index then pull it here
                'Key'    => 'coordinate-data-'.microtime(true).'.json',
                'Body'   => $fileForS3,
                'ACL'    => 'public-read'
            ]);
        } catch (S3Exception $e) {
            // return an error or log it and continue for the user? Depends on the purpose of the endpoint and more specifically the stored file, really
        }
    }
}
