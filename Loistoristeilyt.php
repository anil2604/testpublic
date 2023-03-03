<?php declare(strict_types=1);
/**
 * Process form submissions in the admin area
 *
 * Process, save, check, sanitaze form submissions
 *
 * @link https://www.oddytech.fi/
 * @since 1.0.0
 * @package OddyTech\Loistoristeilyt
 * @subpackage OddyTech\Loistoristeilyt\Admin
 * @author Oddy Tech <production@oddy.fi>
 */

namespace OddyTech\Loistoristeilyt\Admin;
use OddyTech\Loistoristeilyt\Includes;
//error_reporting(E_ALL ^ E_DEPRECATED);
//ini_set("display_errors","1");

class Loistoristeilyt
{
    /**
     * Initialize the class
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        date_default_timezone_set("Europe/Helsinki");
    }

    public function importSailings() {
        $apis = [];
        $costa = new Includes\Costa();
        $apis[] = $costa->getConnection();
        //$holland = new Includes\Holland();
        //$apis[] = $holland->getConnection();
        $day = date('D');

        // Get categories
        $productCategories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        // Filter out categories without description, description is used to find correct id
        $filteredProductCategories = array_filter($productCategories, function($category) {
            return (bool) $category->description;
        });
        // Create array with description as the key and id as the value
        $categories = array_column($filteredProductCategories, 'term_id', 'description');

        foreach ($apis as $api) {
            $ret = $api->requestSearchBySea();
            $apiName = $api->getName();

            if (!is_null($ret)) {
                $array = $api->xmlToArray($ret);

                foreach($array['ResponseSearchBySeaPricing']['SearchBySea']['SailingsList']['SailingsListElement'] as $sailingListElement) {
                    $departure = date_create_from_format('d/m/Y', $sailingListElement['DepartureDate']['$']);
                    $cruiseLine = $sailingListElement['CruiseLine'];
                    $sailingId = $sailingListElement['DepartureDate']['@SailingID'];
                    $departureDate = $departure->format('Y-m-d');
                    $portCode = $sailingListElement['PortName']['@PortCode'];
                    $portName = $sailingListElement['PortName']['$'];
                    $shipCode = $sailingListElement['ShipCode'];
                    $shipName = $sailingListElement['ShipName'];
                    $cruiseLength = ltrim($sailingListElement['CruiseLength'], '0');
                    $geoCode = $sailingListElement['GeoCode'];
                    $packageId = $sailingListElement['PackageId']['$'] ?? '';
                    $itineraryCode = $sailingListElement['PackageId']['@ItineraryCode'];
                    $available = $sailingListElement['SailingSt'] === 'A';
                    //$data = $sailingListElement;

                    if (empty($sailingId)) continue;

                    $query = [
                        [
                            'key' => '_custom_cruise_line',
                            'value' => $cruiseLine,
                            'compare' => '=',
                        ],
                        [
                            'key' => '_custom_api_name',
                            'value' => $apiName,
                            'compare' => '=',
                        ],
                        [
                            'key' => '_custom_sailing_id',
                            'value' => $sailingId,
                            'compare' => '=',
                        ]
                    ];

                    /* SailingID is always needed later but not filled for Holland?
                    if ($apiName == 'Costa') {
                        if (empty($sailingId)) continue;

                        $query[] = [
                            'key' => '_custom_sailing_id',
                            'value' => $sailingId,
                            'compare' => '=',
                        ];
                    } else if ($apiName == 'Holland') {
                        if (empty($packageId) || empty($itineraryCode)) continue;

                        $query[] = [
                            'key' => '_custom_package_id',
                            'value' => $packageId,
                            'compare' => '=',
                        ];
                        $query[] = [
                            'key' => '_custom_itinerary_code',
                            'value' => $itineraryCode,
                            'compare' => '=',
                        ];
                    } else {
                        continue;
                    }
                    */

                    $productExists = get_posts([
                        'numberposts' => 1,
                        'post_type' => 'product',
                        'meta_query' => $query,
                        'post_status' => ['publish', 'private']
                    ]);

                    if ($productExists) {
                        //if ($day != 'Mon') continue; // Updating only once a week / likely not necessary every day / as of 2022-09-09 this is necessary to do every day
                        $productId = $productExists[0]->ID;
                        $product = wc_get_product($productId);
                    } else {
                        $product = new \WC_Product_Variable();
                        $product->set_description($departure->format('j.n.Y') . ' ' . $shipName . ', ' . $cruiseLength . ' yötä' . ', ' . $portName);
                        $product->set_short_description('Lähtöpäivä: ' . $departure->format('j.n.') . ', lähtösatama: ' . $portName . ', kesto: ' . $cruiseLength . ' yötä');
                    }

                    $product->set_name($shipName);
                    if ($available) {
                        $product->set_status('publish');
                    } else {
                        $product->set_status('private');
                    }

                    if (isset($categories[$geoCode])) $product->set_category_ids([$categories[$geoCode]]);
                    $product->save();
                    $productId = $product->get_id();
                    wp_set_object_terms($productId, [$departure->format('j.n.'), $portName, $cruiseLength . ' yötä'], 'product_tag');

                    update_post_meta($productId, '_custom_api_name', $apiName);
                    update_post_meta($productId, '_custom_cruise_line', $cruiseLine);
                    update_post_meta($productId, '_custom_sailing_id', $sailingId);
                    update_post_meta($productId, '_custom_departure_date', $departureDate);
                    update_post_meta($productId, '_custom_port_code', $portCode);
                    update_post_meta($productId, '_custom_port_name', $portName);
                    update_post_meta($productId, '_custom_ship_code', $shipCode);
                    update_post_meta($productId, '_custom_ship_name', $shipName);
                    update_post_meta($productId, '_custom_cruise_length', $cruiseLength);
                    update_post_meta($productId, '_custom_geo_code', $geoCode);
                    update_post_meta($productId, '_custom_package_id', $packageId);
                    update_post_meta($productId, '_custom_itinerary_code', $itineraryCode);
                    //update_post_meta($productId, '_custom_search_by_sea_data', $data); // Likely too much data to save

                    $productAttributeOptions = [];

                    foreach ($sailingListElement['PricesFrom']['PricesList']['PricesListElement'] as $priceListElement) {
                        $category = $priceListElement['Category'];
                        $price = $priceListElement['LAFPrice']['$'];
                        $priceProgram = $priceListElement['PriceProgram']['$'] ?? $priceListElement['PriceProgram'];
                        if (empty($category) || empty($priceProgram)) continue;
                        $wcProductVariationName = $priceProgram . ' - ' . $category;
                        $productAttributeOptions[] = $wcProductVariationName;

                        $variationExists = null;
                        $variations = $product->get_available_variations('object');
                        foreach ($variations as $variation) {
                            if ($variation->get_attribute('category') === $wcProductVariationName || ($category === get_post_meta($variation->get_id(), '_custom_category', true) && $priceProgram === get_post_meta($variation->get_id(), '_custom_price_program', true))) {
                                $variationExists = $variation;
                            }
                        }

                        if ($variationExists) {
                            $variation = $variationExists;
                        } else {
                            $variation = new \WC_Product_Variation();
                        }

                        $variation->set_name($portName);
                        $variation->set_parent_id($productId);
                        $variation->set_attributes(['category' => $wcProductVariationName]);
                        $variation->set_status('publish');
                        $variation->set_virtual(true);
                        $variation->set_downloadable(false);
                        $variation->set_price($price);
                        $variation->set_regular_price($price);
                        $variation->save();
                        $variationId = $variation->get_id();
                        update_post_meta($variationId, '_custom_category', $category);
                        update_post_meta($variationId, '_custom_price_program', $priceProgram);
                    }

                    if (!empty($productAttributeOptions)) {
                        $attribute = new \WC_Product_Attribute();
                        $attribute->set_id(0);
                        $attribute->set_name('Category');
                        $attribute->set_options($productAttributeOptions);
                        $attribute->set_visible(true);
                        $attribute->set_variation(true);

                        $product->set_attributes([$attribute]);
                        $product->save();
                    }

                    sleep(1);
                }
            }
        }
    }

    public function unpublishSailings() {
        $posts = get_posts([
            'numberposts' => -1,
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_custom_departure_date',
                    'value' => date('Y-m-d'),
                    'compare' => '<',
                    'type' => 'DATE'
                ]
            ]
        ]);

        foreach ($posts as $post) {
            $product = wc_get_product($post->ID);
            $product->set_status('private');
            $product->save();
        }
    }

    public function importSailingItineraries() {
        $apis = [];
        $costa = new Includes\Costa();
        $apis[] = $costa->getConnection();
        //$holland = new Includes\Holland();
        //$apis[] = $holland->getConnection();

        $posts = get_posts([
            'numberposts' => -1,
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_custom_departure_date',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE'
                ],
                [
                    'key' => '_custom_itinerary',
                    'compare' => 'NOT EXISTS',
                ]
            ]
        ]);

        foreach ($posts as $post) {
            $id = $post->ID;
            $apiName = get_post_meta($id, '_custom_api_name', true);
            $departureDate = date_create_from_format('Y-m-d', get_post_meta($id, '_custom_departure_date', true))->format('d/m/Y');
            $sailingId = get_post_meta($id, '_custom_sailing_id', true);
            $shipCode = get_post_meta($id, '_custom_ship_code', true);

            foreach ($apis as $api) {
                if ($apiName === $api->getName()) {
                    $ret = $api->requestItinerary($sailingId, $departureDate, $shipCode);

                    if (!is_null($ret)) {
                        $array = $api->xmlToArray($ret);

                        if (!isset($array['ResponseItinerary']['Itinerary']['List']['ListElement']['DWeek'])) {
                            if (isset($array['ResponseItinerary']['Itinerary']['List']['ListElement']) && !empty($array['ResponseItinerary']['Itinerary']['List']['ListElement'])) {
                                update_post_meta($id, '_custom_itinerary', [$array['ResponseItinerary']['Itinerary']['List']]);
                            } else if (isset($array['ResponseItinerary']['Itinerary']['List'][0])) {
                                update_post_meta($id, '_custom_itinerary', $array['ResponseItinerary']['Itinerary']['List']);
                            }
                        }
                    }
                }
            }
        }
    }

    public function importSailingFlights() {
        $apis = [];
        $costa = new Includes\Costa();
        $apis[] = $costa->getConnection();
        //$holland = new Includes\Holland();
        //$apis[] = $holland->getConnection();
        $filepath = $_SERVER['DOCUMENT_ROOT'].'/apilogs/';
        $filename = $filepath.'importSailingFlights_'.date("dMY").'.txt';
		$fp=fopen($filename,"a+");
        fwrite($fp,"\n\n======");
        fwrite($fp,"\nimportSailingFlights");
        $posts = get_posts([
            'numberposts' => -1,
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_custom_departure_date',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE'
                ]
                /*[
                    'key' => '_custom_flights',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_custom_missing_flights',
                    'compare' => 'NOT EXISTS',
                ]*/
            ]
        ]);

        //$guests = ['Guests' => 1, 'BirthDate1' => '1990-01-01', 'GuestType1' => 'Adult'];
        $guests = ['Guests' => 2, 'BirthDate1' => '1990-01-01', 'GuestType1' => 'Adult', 'BirthDate2' => '1990-01-01', 'GuestType2' => 'Adult'];
        //echo "<pre> hellow how are you ";
		//print_r($posts);exit;
        foreach ($posts as $post) {
            $id = $post->ID;
            $apiName = get_post_meta($id, '_custom_api_name', true);
            $sailingId = get_post_meta($id, '_custom_sailing_id', true);
          	fwrite($fp,"\n productId ".$id);
			fwrite($fp,"\n apiname ".$apiName);
          	fwrite($fp,"\n sailingId ".$sailingId);
            foreach ($apis as $api) {
                if ($apiName === $api->getName()) { 
                    $ret = $api->requestComponents($sailingId, $guests, 'Flight');

                    if (!is_null($ret)) {
                        $array = $api->xmlToArray($ret);

                        if (isset($array['ResponseComponents']['Components']['List']['ListElement'])) {
                            $components = $array['ResponseComponents']['Components']['List']['ListElement'];
                            $directions = array_column($components, 'Direction');
                            $missingFlights = true;

                            if (!empty($directions) && $flightKey = array_search('Both', $directions)) {
                                $cities = array_column($array['ResponseComponents']['Components']['List']['ListElement'][$flightKey]['Cities']['City'], 'Code');

                                if (in_array('HEL', $cities)) {
                                    $flightData = $array['ResponseComponents']['Components']['List']['ListElement'][$flightKey];
                                    update_post_meta($id, '_custom_flights', $flightData);
                                    update_post_meta($id, '_custom_mandatory_flight', $flightData['Mandatory'] === 'True' ? 1 : 0);
                                    $missingFlights = false;

                                    $product = wc_get_product($id);
                                    $departureDate = date_create_from_format('Y-m-d', get_post_meta($id, '_custom_departure_date', true))->format('Ymd');
                                    $shipCode = get_post_meta($id, '_custom_ship_code', true);

                                    foreach (['MyCruise', 'MyAllinc'] as $responsePriceProgram) {
                                        $categoriesRet = $api->requestCategories($sailingId, $departureDate, $shipCode, $guests, $responsePriceProgram, [['ComponentType' => 'Flight', 'ComponentCode' => $flightData['ComponentCode'], 'Direction' => $flightData['Direction'], 'Code' => 'HEL', 'Insurance' => $flightData['Insurance']['$'], 'InsuranceAvailableInd' => $flightData['Insurance']['@InsuranceAvailableInd']]]);

                                        if (!is_null($categoriesRet)) {
                                            $categoriesArray = $api->xmlToArray($categoriesRet);

                                            if (isset($categoriesArray['ResponseCategories']['Categories']['List']['ListElement'])) {
                                                $categories = $categoriesArray['ResponseCategories']['Categories']['List']['ListElement'];

                                                foreach ($categories as $responseCategory) {
                                                    foreach ($product->get_available_variations('object') as $variation) {
                                                        $variationId = $variation->get_id();
                                                        $category = get_post_meta($variationId, '_custom_category', true);
                                                        $priceProgram = get_post_meta($variationId, '_custom_price_program', true);
                                                      	$variationPrice=($responseCategory['LAFPrice']/2);
                                                        //$variationPrice=($responseCategory['LAFPrice']);

                                                        if ($category === $responseCategory['Rate'] && $priceProgram === $responsePriceProgram) {
                                                           fwrite($fp,"\n variationId ".$variationId);
                                                           fwrite($fp,"\n variationPrice ".$variationPrice);
                                                           fwrite($fp,"\n category ".$category);
                                                           //update_post_meta($variationId, '_custom_price_with_flights', $responseCategory['LAFPrice']/2);
                                                           update_post_meta($variationId, 'price', $variationPrice);
                                                           update_post_meta($variationId, '_regular_price', $variationPrice);
														   //update_post_meta($variationId, '_sale_price', $variationPrice);
                                                           update_post_meta($variationId, '_custom_price_with_flights', $variationPrice);
														  break;
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        sleep(1);
                                    }
                                }
                            }
                            if ($missingFlights) update_post_meta($id, '_custom_missing_flights', 1);
                        }
                    }
                    sleep(1);
                }
            }
        }
    }	
}