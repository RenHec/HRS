<?php

namespace App\Http\Controllers\V1\Principal\Reservation;


use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\V1\Principal\Room;
use App\Models\V1\Catalogo\Status;
use Illuminate\Support\Facades\DB;
use App\Models\V1\Principal\Client;
use App\Models\V1\Catalogo\Movement;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\ApiController;
use App\Models\V1\Catalogo\Municipio;
use App\Models\V1\Principal\PictureRoom;
use App\Models\V1\Principal\Reservation;
use App\Models\V1\Principal\ReservationOfert;
use App\Models\V1\Principal\ReservationDetail;
use App\Models\V1\Principal\BinnacleReservation;

class ReservationController extends ApiController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $data = Reservation::with('client.phones', 'user', 'status')->where('status_id', '!=', Status::ANULADO)->where('reserva', true)->get();
        return $this->showAll($data);
    }

    public function confirmado()
    {
        $data = DB::table('reservations')
            ->join('clients', 'reservations.client_id', 'clients.id')
            ->join('coins', 'reservations.coin_id', 'coins.id')
            ->select(
                'reservations.id AS id',
                'reservations.code AS code',
                DB::RAW('CONCAT(reservations.code," | ",clients.first_name," ",clients.surname) AS name')
            )
            ->where('reservations.status_id', Status::CONFIRMADO)
            ->where('reservations.reserva', true)
            ->get();

        return $this->showAll($data);
    }

    public function pendiente()
    {
        $data = DB::table('reservations')
            ->join('clients', 'reservations.client_id', 'clients.id')
            ->join('coins', 'reservations.coin_id', 'coins.id')
            ->select(
                'reservations.id AS id',
                DB::RAW('CONCAT(reservations.code," | ",clients.first_name," ",clients.surname) AS name'),
                DB::RAW('CONCAT(coins.symbol," ",FORMAT(reservations.total,2)) AS total')
            )
            ->where('reservations.status_id', Status::PENDIENTE)
            ->where('reservations.reserva', true)
            ->get();

        return $this->showAll($data);
    }

    public function promocion(Room $room) //Pendiente
    {
        $data = DB::table('oferts_rooms')
            ->join('coins', 'oferts_rooms.coin_id', 'coins.id')
            ->select(
                'oferts_rooms.id AS id',
                'oferts_rooms.price AS sf_price',
                DB::RAW('CONCAT(oferts_rooms.accommodation," noches por: ",coins.symbol," ",FORMAT(oferts_rooms.price,2)) AS name')
            )
            ->where('oferts_rooms.room_id', $room->id)
            ->where('active', true)
            ->get();

        return $this->showAll($data);
    }

    public function calendario(Status $status)
    {
        $data = DB::table('reservations')
            ->join('clients', 'reservations.client_id', 'clients.id')
            ->join('reservations_details', 'reservations.id', 'reservations_details.reservation_id')
            ->select(
                'reservations.id AS id',
                DB::RAW('CONCAT(reservations.code," - ",clients.name) AS name'),
                'reservations_details.arrival_date AS arrival_date',
                'reservations_details.departure_date AS departure_date',
                DB::RAW('TIME(reservations_details.arrival_date) AS tiempo')
            )
            ->wheere('reservations.status_id', $status->id)
            ->where('reservations.reserva', true)
            ->distinct('reservations.id')
            ->get();

        return $this->showAll($data);
    }

    public function buscar_habitaciones(Request $request)
    {
        $servicios = $request->servicios == 'null' || is_null($request->servicios) ? null : $request->servicios['id'];
        $inicio = $request->inicio == 'null' || is_null($request->inicio) ? null : $request->inicio;
        $fin = $request->fin == 'null' || is_null($request->fin) ? null : $request->fin;
        $hora = $request->hora == 'null' || is_null($request->hora) ? null : $request->hora;
        $cantidad = $request->cantidad == 'null' || is_null($request->cantidad) ? null : $request->cantidad;

        $data = DB::table('rooms')
            ->join('type_beds', 'rooms.type_bed_id', 'type_beds.id')
            ->join('type_rooms', 'rooms.type_room_id', 'type_rooms.id')
            ->join('type_services', 'rooms.type_service_id', 'type_services.id')
            ->select(
                'rooms.id AS id',
                DB::RAW('CONCAT(rooms.number," ",type_services.name," - ",rooms.name) AS name'),
                'rooms.amount_people AS amount_people',
                'type_rooms.name AS type_room',
                DB::RAW('CONCAT(rooms.amount_bed," ",type_beds.name) AS type_bed'),
                'rooms.description AS description',
                'rooms.type_service_id AS type_service_id',
                'rooms.resta AS espacio'
            )
            ->when($inicio && $fin && !$hora, function ($query) use ($inicio, $fin) {
                $query->whereNotExists(function ($subquery) use ($inicio, $fin) {
                    $subquery->select(DB::raw(1))
                        ->from('reservations_details')
                        ->whereIn('reservations_details.status_id', [Status::PENDIENTE, Status::EN_PROCESO, Status::CONFIRMADO])
                        //->whereBetween(DB::RAW('DATE_FORMAT(reservations_details.arrival_date, "%Y-%m-%d")'), [$inicio, $fin])
                        //->whereBetween(DB::RAW('DATE_FORMAT(reservations_details.departure_date, "%Y-%m-%d")'), [$inicio, $fin])
                        ->whereBetween(DB::RAW("'$inicio'"), [DB::RAW('DATE_FORMAT(reservations_details.arrival_date, "%Y-%m-%d")'), DB::RAW('DATE_FORMAT(reservations_details.departure_date, "%Y-%m-%d")')])
                        ->whereRaw('reservations_details.room_id = rooms.id');
                });
            })
            ->when($inicio && $fin && !$hora, function ($query) use ($inicio, $fin) {
                $query->whereNotExists(function ($subquery) use ($inicio, $fin) {
                    $subquery->select(DB::raw(1))
                        ->from('reservations_details')
                        ->whereIn('reservations_details.status_id', [Status::PENDIENTE, Status::EN_PROCESO, Status::CONFIRMADO])
                        //->whereBetween(DB::RAW('DATE_FORMAT(reservations_details.arrival_date, "%Y-%m-%d")'), [$inicio, $fin])
                        //->whereBetween(DB::RAW('DATE_FORMAT(reservations_details.departure_date, "%Y-%m-%d")'), [$inicio, $fin])
                        ->whereBetween(DB::RAW("'$fin'"), [DB::RAW('DATE_FORMAT(reservations_details.arrival_date, "%Y-%m-%d")'), DB::RAW('DATE_FORMAT(reservations_details.departure_date, "%Y-%m-%d")')])
                        ->whereRaw('reservations_details.room_id = rooms.id');
                });
            })
            ->when($inicio && $fin && $hora, function ($query) use ($inicio, $hora) {
                $query->whereNotExists(function ($subquery) use ($inicio, $hora) {
                    $subquery->select(DB::raw(1))
                        ->from('reservations_details')
                        ->whereIn('reservations_details.status_id', [Status::PENDIENTE, Status::EN_PROCESO, Status::CONFIRMADO])
                        ->whereBetween(DB::RAW("'$inicio $hora'"), [DB::RAW('DATE_FORMAT(reservations_details.arrival_date, "%Y-%m-%d %H:%i")'), DB::RAW('DATE_FORMAT(reservations_details.departure_date, "%Y-%m-%d %H:%i")')])
                        ->whereRaw('reservations_details.room_id = rooms.id');
                });
            })
            ->when($servicios, function ($query) use ($servicios) {
                $query->where('rooms.type_service_id', $servicios);
            })
            /*->when($cantidad, function ($query) use ($cantidad) {
                $query->where(DB::RAW('rooms.amount_people - rooms.resta'), '>', $cantidad - 1);
            })*/
            ->whereNull('rooms.deleted_at')
            ->get();

        $array = array();
        $array_ids = array();

        foreach ($data as $value) {
            $photo = PictureRoom::where('room_id', $value->id)->orderBy('position', 'ASC')->first();

            $info['id'] = $value->id;
            $info['name'] = $value->name;
            $info['amount_people'] = $value->amount_people;
            $info['type_room'] = $value->type_room;
            $info['type_bed'] = $value->type_bed;
            $info['description'] = $value->description;
            $info['type_service_id'] = $value->type_service_id;
            $info['photo'] = is_null($photo) ? null : $photo->picture;
            $info['esconder'] = false;

            array_push($array, $info);
            array_push($array_ids, $value->id);
        }

        $precios = DB::table('rooms_prices')
            ->join('coins', 'rooms_prices.coin_id', 'coins.id')
            ->join('type_charge', 'rooms_prices.type_charge_id', 'type_charge.id')
            ->select(
                'rooms_prices.room_id AS id',
                DB::RAW('CONCAT(type_charge.name," - ",coins.symbol," ",FORMAT(rooms_prices.price,2)) AS name'),
                'rooms_prices.price AS sf_price',
                'coins.id AS minutos',
                'coins.id AS coin_id'
            )
            ->whereIn('rooms_prices.room_id', $array_ids)
            ->get();

        $masajes = DB::table('rooms_massages')
            ->join('type_massages', 'rooms_massages.type_massage_id', 'type_massages.id')
            ->join('coins', 'type_massages.coin_id', 'coins.id')
            ->select(
                'rooms_massages.room_id AS id',
                DB::RAW('CONCAT(type_massages.name," | Precio: ",coins.symbol," ",FORMAT(type_massages.price,2)," | Tiempo: ",type_massages.time," min.") AS name'),
                'type_massages.price AS sf_price',
                'type_massages.time AS minutos',
                'coins.id AS coin_id'
            )
            ->whereIn('rooms_massages.room_id', $array_ids)
            ->get();

        return $this->successResponse(['habitaciones' => $array, 'precios' => $precios, 'masajes' => $masajes]);
    }

    public function buscar_habitacion(Room $room, $inicio, $fin)
    {
        $data = DB::table('rooms')
            ->join('type_beds', 'rooms.type_bed_id', 'type_beds.id')
            ->join('type_rooms', 'rooms.type_room_id', 'type_rooms.id')
            ->join('coins', 'rooms.coin_id', 'coins.id')
            ->join('type_services', 'rooms.type_service_id', 'type_services.id')
            ->select(
                'rooms.id AS id',
                DB::RAW('CONCAT(rooms.number," ",type_services.name," - ",rooms.name) AS name'),
                'rooms.amount_people AS amount_people',
                'type_rooms.name AS type_room',
                DB::RAW('CONCAT(rooms.amount_bed," ",type_beds.name) AS type_bed'),
                DB::RAW('CONCAT(coins.symbol," ",FORMAT(rooms.price,2)) AS price'),
                'rooms.price AS sf_price',
                'rooms.description AS description',
                'coins.id AS coin_id'
            )
            ->whereNotExists(function ($query) use ($inicio, $fin) {
                $query->select(DB::raw(1))
                    ->from('reservations')
                    ->join('reservations_details', 'reservations_details.reservation_id', 'reservations.id')
                    ->whereIn('reservations.status_id', [Status::PENDIENTE, Status::EN_PROCESO, Status::CONFIRMADO])
                    ->whereBetween(DB::RAW('DATE_FORMAT(reservations.departure_date, "%Y-%m-%d")'), [$inicio, $fin])
                    ->whereRaw('reservations_details.room_id = rooms.id');
            })
            ->where('rooms.id', $room->id)
            ->whereNull('rooms.deleted_at')
            ->get();

        $mensaje = count($data) > 0 ? "Si puede reservar." : "No, no puede reservar";
        $error = count($data) > 0 ? true : false;

        return $this->successResponse(['mensaje' => $mensaje, 'error' => $error]);
    }

    public function precios(Room $room)
    {
        $data = DB::table('rooms_prices')
            ->join('rooms', 'rooms_prices.room_id', 'rooms.id')
            ->join('coins', 'rooms.coin_id', 'coins.id')
            ->join('type_charge', 'rooms_prices.type_charge_id', 'type_charge.id')
            ->select(
                'rooms_prices.id AS rooms_prices',
                DB::RAW('CONCAT(type_charge.name," - ",coins.symbol," ",FORMAT(rooms_prices.price,2)) AS name'),
                'rooms_prices.price AS sf_price'
            )
            ->where('rooms_prices.room_id', $room->id)
            ->get();

        return $this->successResponse($data);
    }

    public function store(Request $request)
    {
        //$this->validate($request, $this->rules(), $this->messages());

        try {
            DB::beginTransaction();

            $generar = Reservation::whereYear('created_at', date('Y'))->count();
            $generar += 1;
            $user = Auth::user()->id;

            $municipio = Municipio::find($request->municipality_id['id']);

            $cliente = Client::firstOrCreate(
                ['nit' => $request->nit],
                [
                    'name' => $request->name,
                    'email' => $request->email,
                    'business' => $request->business,
                    'ubication' => $request->ubication,
                    'departament_id' => $municipio->departament_id,
                    'municipality_id' => $municipio->id
                ]
            );

            $data = $request->all();
            $data['code'] = $this->generadorCodigo($generar);
            $data['nit'] = $cliente->nit;
            $data['name'] = $cliente->name;
            $data['ubication'] = $municipio->getFullNameAttribute() . ', ' . $cliente->ubication;
            $data['total'] = 0;
            $data['total_reservation'] = 0;
            $data['total_product'] = 0;
            $data['reserva'] = true;
            $data['responsable'] = $request->event ? $request->responsable : null;

            $data['client_id'] = $cliente->id;
            $data['user_id'] = $user;
            $data['status_id'] = Status::PENDIENTE;
            $data['coin_id'] = $request->coin_id;

            $reservation = Reservation::create($data);
            $multiplicar = !is_null($request->cantidad) ? $request->cantidad : 1;

            $start = is_null($request->hora) ? Carbon::createFromFormat('Y-m-d', $request->arrival_date) : date('Y-m-d H:i:s', strtotime($request->arrival_date . ' ' . $request->hora));
            $end = is_null($request->hora) ? Carbon::createFromFormat('Y-m-d', $request->departure_date) : date('Y-m-d H:i:s', strtotime($request->arrival_date . ' ' . $request->hora));
            $accommodation = is_null($request->hora) ? $start->diffInDays($end) : 0;

            foreach ($request->details as $value) {

                if ($reservation->event) {
                    $cliente = Client::firstOrCreate(
                        ['nit' => $request->nit],
                        [
                            'name' => $request->name,
                            'email' => $value['email'],
                            'business' => false,
                            'ubication' => null,
                            'departament_id' => 1,
                            'municipality_id' => 1
                        ]
                    );
                }

                $room = Room::find($value['room_id']);
                $minute = "+{$value['minutos']} minute";
                $detail = ReservationDetail::create(
                    [
                        'arrival_date' => $start,
                        'departure_date' => is_null($request->hora) ? $end : date('Y-m-d H:i:s', strtotime($minute, strtotime($end))),
                        'accommodation' => $accommodation,
                        'quote' => !is_null($request->cantidad) ? $request->cantidad : 0,
                        'authorization_code' => 'code',
                        'price' => $value['price'],
                        'sub' => intval($accommodation) == 0 ? $multiplicar * $value['price'] : intval($accommodation) * $value['price'],
                        'ofert' => false,
                        'guest' => $cliente->name,
                        'description' => $value['description'],
                        'reservation_id' => $reservation->id,
                        'room_id' => $value['room_id'],
                        'coin_id' => $reservation->coin_id,
                        'client_id' => $cliente->id,
                        'type_service_id' => $room->type_service_id,
                        'status_id' => Status::PENDIENTE
                    ]
                );

                $room->resta += $multiplicar;
                if (!is_null($request->cantidad)) {
                    $room->save();
                }

                /*if (!is_null($value['ofert'])) {
                    ReservationOfert::create(
                        [
                            'reservation_id' => $reservation->id,
                            'reservation_detail_id' => $detail->id,
                            'ofert_room_id' => $value['ofert']
                        ]
                    );
                }*/

                BinnacleReservation::create(
                    [
                        'start' => $detail->arrival_date,
                        'end' => $detail->departure_date,
                        'days' => 60,
                        'reservation_detail_id' => $detail->id,
                        'movement_id' => Movement::PROGRAMADA,
                        'user_id' => $user,
                        'type_service_id' => $detail->type_service_id
                    ]
                );

                $reservation->total_reservation += $detail->sub;
            }

            if ($reservation->total_reservation > 0) {
                $reservation->total = $reservation->total_reservation;
                $reservation->save();
            }

            DB::commit();

            return $this->successResponse('Registro agregado.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 423);
        }
    }

    public function show(Reservation $reservation)
    {
        $data = DB::table('reservations_details')
            ->join('reservations', 'reservations_details.reservation_id', 'reservations.id')
            ->join('clients', 'reservations.client_id', 'clients.id')
            ->join('coins', 'reservations_details.coin_id', 'coins.id')
            ->select(
                'reservations.id AS id',
                DB::RAW('CONCAT(reservations.code," - ",clients.name) AS name'),
                'reservations_details.description',
                'reservations_details.accommodation',
                'reservations_details.quote',
                'reservations_details.guest',
                DB::RAW('CONCAT(coins.symbol," ",FORMAT(reservations_details.price,2)) AS precio'),
                DB::RAW('CONCAT(coins.symbol," ",FORMAT(reservations_details.sub,2)) AS sub'),
                DB::RAW('CONCAT(coins.symbol," ",FORMAT(reservations.total,2)) AS total')
            )
            ->where('reservations_details.reservation_id', $reservation->id)
            ->get();

        return $this->showAll($data);
    }

    public function update(Request $request, Reservation $reservation)
    {
        //$this->validate($request, $this->rules($reservation->id), $this->messages());

        try {
            $reservation->nit = $request->nit;
            $reservation->name = $request->name;
            $reservation->ubication = $request->ubication;
            $reservation->responsable = $request->responsable;

            if (!$reservation->isDirty())
                return $this->errorResponse('No hay datos para actualizar', 423);

            $reservation->save();

            return $this->successResponse('Registro agregado.');
        } catch (\Exception $e) {
            return $this->errorResponse('Error en el controlador', 423);
        }
    }

    public function destroy(Reservation $reservation)
    {
        try {
            DB::beginTransaction();
            $reservation->status_id = Status::CANCELACION;
            $reservation->save();

            $detalles = ReservationDetail::where('reservation_id', $reservation->id)->get();

            foreach ($detalles as $key => $value) {
                $value->status_id = Status::CANCELACION;
                $value->save();

                BinnacleReservation::where('reservation_detail_id', $value->id)
                    ->update(
                        [
                            'active' => false
                        ]
                    );

                BinnacleReservation::create(
                    [
                        'start' => date('Y-m-d h:i:s'),
                        'end' => date('Y-m-d h:i:s'),
                        'days' => 0,
                        'subtraction' => 0,
                        'reservation_detail_id' => $value->id,
                        'movement_id' => Movement::CANCELADA,
                        'user_id' => Auth::user()->id,
                        'type_service_id' => $value->type_service_id
                    ]
                );
            }

            DB::commit();

            return $this->successResponse('Registro anulado.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Error en el controlador', 423);
        }
    }
}
