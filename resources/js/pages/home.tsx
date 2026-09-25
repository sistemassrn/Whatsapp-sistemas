import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Home() {
    const { data, setData, post, processing, errors } = useForm({
        access_key: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/testing-access');
    }

    return (
        <>
            <Head title="Inicio" />

            <main className="flex min-h-screen items-center justify-center bg-gray-50 px-6 py-12 text-black">
                <section className="w-full max-w-md rounded-3xl border border-black/10 bg-white p-8 text-white shadow-2xl shadow-black/30">

                    <h1 className=" text-3xl font-semibold tracking-tight text-black">
                        Entrar con operador de prueba
                    </h1>

                    <form onSubmit={submit} className="mt-8 space-y-5">
                        <div>
                            <label
                                htmlFor="access_key"
                                className="text-sm font-medium text-black"
                            >
                                Acceso temporal con clave en .env
                            </label>

                            <input
                                id="access_key"
                                name="access_key"
                                type="password"
                                value={data.access_key}
                                onChange={(event) =>
                                    setData('access_key', event.target.value)
                                }
                                className="mt-2 w-full rounded-xl border border-gray-300 px-4 py-3 text-black outline-none transition"
                                placeholder="demo123"
                                autoComplete="off"
                            />

                            {errors.access_key && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.access_key}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Procesando...' : 'Acceder'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
