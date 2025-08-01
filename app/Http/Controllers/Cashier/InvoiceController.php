<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Inventory;
use App\Models\Technician;
use Illuminate\Pagination\LengthAwarePaginator;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();
        $search = $request->input('search');

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentAll = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('client', fn($q) => $q->where('name', 'like', "%$search%"))
                        ->orWhereHas('vehicle', fn($q) => $q->where('plate_number', 'like', "%$search%"))
                        ->orWhere('customer_name', 'like', "%$search%")
                        ->orWhere('vehicle_name', 'like', "%$search%")
                        ->orWhere('invoice_no', 'like', "%$search%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends(['search' => $search]);


        return view('cashier.invoice', compact('clients', 'vehicles', 'parts', 'technicians', 'history', 'recentAll', 'search'));
    }

    public function create()
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::select('id', 'item_name', 'quantity', 'selling')->get(); // Select quantity as the remaining stock
        $technicians = Technician::all();
        $history = collect([]);

        return view('cashier.invoice', compact('clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'customer_name' => 'nullable|string',
            'vehicle_name' => 'nullable|string',
            'plate' => 'nullable|string',
            'model' => 'nullable|string',
            'year' => 'nullable|string',
            'color' => 'nullable|string',
            'odometer' => 'nullable|string',
            'subtotal' => 'required|numeric',
            'total_discount' => 'nullable|numeric|min:0',
            'vat_amount' => 'required|numeric',
            'grand_total' => 'required|numeric',
            'payment_type' => 'required|string',
            'status' => 'required|in:unpaid,paid,cancelled,voided',
            'service_status' => 'required|in:pending,in_progress,done',
            'invoice_no' => 'required|string|unique:invoices,invoice_no',
            'number' => 'nullable|string',
            'address' => 'nullable|string',
            'created_date' => 'nullable|date',
        ]);



        // ✅ Automatically create client if none selected but manual name exists
        $clientId = $request->client_id;
        if (!$clientId && $request->customer_name) {
            $client = Client::create([
                'name' => $request->customer_name,
                'address' => $request->address,
                'phone' => null,
                'email' => null,
            ]);
            $clientId = $client->id;
        }

        // ✅ Vehicle logic
        $vehicleId = $request->vehicle_id;
        if ($vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle) {
                $vehicle->update([
                    'plate_number' => $request->plate,
                    'model' => $request->model,
                    'year' => $request->year,
                    'color' => $request->color,
                    'odometer' => $request->odometer,
                ]);
            }
        } else if ($request->plate || $request->model || $request->year || $request->color || $request->odometer) {
            $vehicle = Vehicle::create([
                'plate_number' => $request->plate,
                'model' => $request->model,
                'year' => $request->year,
                'color' => $request->color,
                'odometer' => $request->odometer,
                'client_id' => $clientId,  // ✅ link to possibly new client
            ]);
            $vehicleId = $vehicle->id;
        } else {
            $vehicleId = null;
        }

        $date = $request->input('created_date') ?? now();


        $invoice = new Invoice();
        $invoice->forceFill([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'customer_name' => $request->customer_name,
            'vehicle_name' => $request->vehicle_name,
            'source_type' => 'invoicing',
            'service_status' => $request->service_status ?? 'pending',
            'status' => $request->status ?? 'unpaid',
            'subtotal' => $request->subtotal,
            'total_discount' => $request->input('total_discount', 0),
            'vat_amount' => $request->vat_amount,
            'grand_total' => $request->grand_total,
            'payment_type' => $request->payment_type,
            'invoice_no' => $request->invoice_no,
            'number' => $request->number,
            'address' => $request->address,
            'created_at' => $date, // ✅ this will now be respected
        ])->save();

        // ✅ Items & Jobs remain same...
        $invoice->items()->delete();
        if ($request->has('items')) {
            foreach ($request->items as $item) {
                $original = $item['original_price'] ?? $item['price'] ?? $item['manual_selling_price'] ?? 0;


                $discount = $item['discount_value'] ?? 0;
                $effective = $original - $discount;
                $qty = $item['quantity'] ?? 0;
                $lineTotal = $qty * $effective;

                $invoice->items()->create([
                    'part_id' => $item['part_id'] ?? null,
                    'manual_part_name' => $item['manual_part_name'] ?? null,
                    'manual_serial_number' => $item['manual_serial_number'] ?? null,
                    'manual_acquisition_price' => $item['manual_acquisition_price'] ?? null,
                    'manual_selling_price' => $item['manual_selling_price'] ?? null,
                    'quantity' => $qty,
                    'original_price' => $original,
                    'discount_value' => $discount,
                    'discounted_price' => $lineTotal, // line_total is now stored as discounted_price
                    'line_total' => $lineTotal,
                ]);

            }
        }
        if ($request->has('jobs')) {
            foreach ($request->jobs as $job) {
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id' => $job['technician_id'] ?? null,
                    'total' => $job['total'] ?? 0,
                ]);
            }
        }

        if ($invoice->status === 'paid') {
            foreach ($invoice->items as $item) {
                $inventory = Inventory::find($item->part_id);
                if ($inventory) {
                    $inventory->deductQuantity($item->quantity);
                }
            }
        }

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice created!');
    }


    public function edit($id)
    {
        $invoice = Invoice::with(['items', 'jobs'])->findOrFail($id);
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentAll = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->orderBy('created_at', 'desc')
            ->paginate(10); // ✅ Now returns a paginator object compatible with ->links()


        return view('cashier.invoice', compact('invoice', 'clients', 'vehicles', 'parts', 'technicians', 'history', 'recentAll'));
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::with('items')->findOrFail($id);
        $prevStatus = $invoice->status;

        // If this is a quick "mark as paid" or service_status update (not full edit)
        if ($request->has('status') && $request->method() == 'PUT' && !$request->has('items')) {
            $invoice->update([
                'status' => $request->status,
                'service_status' => $request->service_status ?? $invoice->service_status,
            ]);

            // Deduct inventory ONLY if changing from not paid → paid
            if ($prevStatus !== 'paid' && $request->status === 'paid') {
                foreach ($invoice->items as $item) {
                    $inventory = Inventory::find($item->part_id);
                    if ($inventory) {
                        $inventory->deductQuantity($item->quantity);
                    }
                }
            }

            return redirect()->route('cashier.invoice.index')->with('success', 'Status updated!');
        }

        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'customer_name' => 'nullable|string',
            'vehicle_name' => 'nullable|string',
            'plate' => 'nullable|string',
            'model' => 'nullable|string',
            'year' => 'nullable|string',
            'color' => 'nullable|string',
            'odometer' => 'nullable|string',
            'subtotal' => 'required|numeric',
            'total_discount' => 'nullable|numeric|min:0',
            'vat_amount' => 'required|numeric',
            'grand_total' => 'required|numeric',
            'payment_type' => 'required|string',
            'status' => 'required|in:unpaid,paid,cancelled,voided',
            'service_status' => 'required|in:pending,in_progress,done',
            'invoice_no' => 'required|string|unique:invoices,invoice_no,' . $invoice->id,

            'created_date' => 'nullable|date',
        ]);

        // ✅ Ensure new client created if no client_id
        $clientId = $request->client_id;
        if (!$clientId && $request->customer_name) {
            $client = Client::create([
                'name' => $request->customer_name,
                'address' => $request->address,
                'phone' => null,
                'email' => null,
            ]);
            $clientId = $client->id;
        }

        $vehicleId = $request->vehicle_id;
        if ($vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle) {
                $vehicle->update([
                    'plate_number' => $request->plate,
                    'model' => $request->model,
                    'year' => $request->year,
                    'color' => $request->color,
                    'odometer' => $request->odometer,
                ]);
            }
        } else if ($request->plate || $request->model || $request->year || $request->color || $request->odometer) {
            $vehicle = Vehicle::create([
                'plate_number' => $request->plate,
                'model' => $request->model,
                'year' => $request->year,
                'color' => $request->color,
                'odometer' => $request->odometer,
                'client_id' => $clientId,  // ✅ fixed
            ]);
            $vehicleId = $vehicle->id;
        } else {
            $vehicleId = null;
        }

        $date = $request->input('created_date') ?? now();

        $invoice->update([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'customer_name' => $request->customer_name,
            'vehicle_name' => $request->vehicle_name,
            'source_type' => 'invoicing',
            'service_status' => $request->service_status ?? 'pending',
            'status' => $request->status ?? 'unpaid',
            'subtotal' => $request->subtotal,
            'total_discount' => $request->input('total_discount', 0),
            'created_at' => $date,

            'vat_amount' => $request->vat_amount,
            'grand_total' => $request->grand_total,
            'payment_type' => $request->payment_type,
            'invoice_no' => $request->invoice_no,
        ]);



        // Update items (delete old, add new)
        $invoice->items()->delete();
        if ($request->has('items')) {
            foreach ($request->items as $item) {
                $original = $item['original_price'] ?? $item['price'] ?? $item['manual_selling_price'] ?? 0;


                $discount = $item['discount_value'] ?? 0;
                $effective = $original - $discount;
                $qty = $item['quantity'] ?? 0;
                $lineTotal = $qty * $effective;

                $invoice->items()->create([
                    'part_id' => $item['part_id'] ?? null,
                    'manual_part_name' => $item['manual_part_name'] ?? null,
                    'manual_serial_number' => $item['manual_serial_number'] ?? null,
                    'manual_acquisition_price' => $item['manual_acquisition_price'] ?? null,
                    'manual_selling_price' => $item['manual_selling_price'] ?? null,
                    'quantity' => $qty,
                    'original_price' => $original,
                    'discount_value' => $discount,
                    'discounted_price' => $lineTotal, // line_total is now stored as discounted_price
                    'line_total' => $lineTotal,
                ]);


            }
        }



        // Update jobs (delete old, add new)
        $invoice->jobs()->delete();
        if ($request->has('jobs')) {
            foreach ($request->jobs as $job) {
                $techId = !empty($job['technician_id']) ? $job['technician_id'] : null;
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id' => $techId,
                    'total' => $job['total'] ?? 0,
                ]);
            }
        }

        // Deduct inventory only if marking as paid, and was not already paid
        if ($prevStatus !== 'paid' && $request->status === 'paid') {
            foreach ($invoice->items as $item) {
                $inventory = Inventory::find($item->part_id);
                if ($inventory) {
                    $inventory->deductQuantity($item->quantity);
                }
            }
        }

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice updated!');
    }

    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->items()->delete();
        $invoice->jobs()->delete();
        $invoice->delete();

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice deleted!');
    }

    public function view($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        return view('cashier.invoice-view', compact('invoice'));
    }

    public function show($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        return view('cashier.invoice-view', compact('invoice'));
    }

    public function ajaxClients(Request $request)
    {
        $search = $request->get('q', '');
        return Client::where('name', 'like', "%$search%")
            ->select('id', 'name', 'phone as number', 'address')

            ->limit(20)
            ->get();
    }

    public function ajaxVehicles(Request $request)
    {
        $search = $request->get('q', '');
        $clientId = $request->get('client_id');

        return Vehicle::where('plate_number', 'like', "%$search%")
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->select('id', 'plate_number', 'model', 'year', 'color', 'odometer')
            ->limit(20)
            ->get();

    }

    public function liveSearch(Request $request)
    {
        $search = $request->input('search');

        $results = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('client', fn($q) => $q->where('name', 'like', "%$search%"))
                        ->orWhereHas('vehicle', fn($q) => $q->where('plate_number', 'like', "%$search%"))
                        ->orWhere('customer_name', 'like', "%$search%")
                        ->orWhere('vehicle_name', 'like', "%$search%")
                        ->orWhere('invoice_no', 'like', "%$search%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('cashier.partials.invoice-results', ['results' => $results])->render();
    }




}
