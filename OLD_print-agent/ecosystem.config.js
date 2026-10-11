module.exports = {
    apps: [
        {
            name: 'kazakora-print-poller',
            script: './src/poller-process.js',
            cwd: __dirname,
            autorestart: true,
            max_restarts: 50,
            restart_delay: 5000,
            watch: false,
            env: {
                NODE_ENV: 'production',
            },
        },
        {
            name: 'kazakora-print-worker',
            script: './src/worker-process.js',
            cwd: __dirname,
            autorestart: true,
            max_restarts: 50,
            restart_delay: 5000,
            watch: false,
            env: {
                NODE_ENV: 'production',
            },
        },
    ],
};
