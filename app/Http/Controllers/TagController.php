<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Tag;

class TagController extends Controller
{
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name_tag' => 'required|string|max:100',
            ]);

            $user = Auth::user();
            if (!$user) return response()->json(['error' => 'No autenticado'], 401);

            $existe = Tag::where('user_id', $user->id)
                        ->where('name_tag', $request->name_tag)
                        ->exists();

            if ($existe) {
                return response()->json(['error' => 'La etiqueta ya existe para este usuario.'], 409);
            }

            $tag = Tag::create([
                'name_tag' => $request->name_tag,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Etiqueta creada exitosamente.',
                'tag' => $tag
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear etiqueta: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ocurrió un error al crear la etiqueta.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function index()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);

        try {
            $tags = Tag::where('user_id', $user->id)->get();

            return response()->json([
                'tags' => $tags,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener etiquetas: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al obtener etiquetas',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function sugerirDesdeIA(Request $request)
    {
        $request->validate([
            'descripcion' => 'required|string',
            'monto' => 'required|numeric',
        ]);

        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);

        try {
            $sugerida = $this->etiquetarConIA($request->descripcion, $request->monto);
            return response()->json([
                'sugerencia' => $sugerida,
            ]);
        } catch (\Exception $e) {
            Log::error('Error con IA: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al sugerir etiqueta.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

private function etiquetarConIA(string $descripcion, float $monto): string
{
    $apiKey = config('services.groq.key');

    $user = Auth::user();
    if (!$user) throw new \Exception('Usuario no autenticado');

    $opciones = Tag::where('user_id', $user->id)->pluck('name_tag')->toArray();

    $lista = empty($opciones)
        ? 'Otros'
        : implode(', ', $opciones);

    $prompt = <<<PROMPT
Actúa como un asistente de finanzas personales. Tienes una lista de etiquetas existentes del usuario:

$lista

Tu tarea es:
1. Si alguna de estas etiquetas describe bien el movimiento, responde solo con una de ellas.
2. Si **ninguna** etiqueta aplica bien, entonces **inventa una nueva etiqueta corta y precisa** (1 o 2 palabras máximo) que clasifique este movimiento.

No expliques tu respuesta. Solo responde con una sola etiqueta (ya sea existente o nueva).

Movimiento:
Descripción: "$descripcion"
Monto: $monto

Etiqueta sugerida:
PROMPT;

    // ✅ Lista de modelos disponibles (orden de prioridad)
    $modelos = [
        'llama3-70b-8192',
        'llama-3.1-8b-instant',
        'gemma2-9b-it',
    ];

    foreach ($modelos as $modelo) {
        try {
            $response = Http::withOptions([
                
            ])->withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $modelo,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ]);

            if ($response->successful()) {
                Log::info("IA: respuesta exitosa con modelo $modelo");
                return trim($response['choices'][0]['message']['content']);
            }

            Log::warning("IA: modelo $modelo falló → código HTTP: " . $response->status());
        } catch (\Exception $e) {
            Log::error("IA: error al usar modelo $modelo → " . $e->getMessage());
        }
    }

    throw new \Exception('Todos los modelos de Groq fallaron al generar la etiqueta.');
}
}