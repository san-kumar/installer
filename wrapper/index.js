'use strict';

let child_process = require('child_process');
let environment = require('./environment');
let headers = require('./headers');
let splitter = require('./splitter');
let fs = require('fs');

exports.handler = function (event, context) {
    try {
        let strToReturn = '';
        let env = environment.parse(event);
        let ext = env.SCRIPT_EXT;

        if (ext === 'php') {
            let proc = child_process.spawn('./php', [{stdio: 'inherit'}], {env: env});
            let body;

            if (event.body && (event.body.length > 0)) {
                body = event.isBase64Encoded ? Buffer.from(event.body, 'base64').toString('binary') : event.body;
                //proc.stdin.setEncoding('utf-8');
                proc.stdin.write(body + "\r\n");
                proc.stdin.end();
            }

            proc.stdout.on('data', function (data) {
                strToReturn += data.toString();
            });

            proc.on('close', function (code) {
                if (code !== 0) {
                    return context.done(new Error("Process exited with non-zero status code"));
                }

                let [header, content] = splitter.split(strToReturn, "\r\n\r\n");
                let headerArr = headers.parse(header);
                let statusCode = parseInt(headerArr['status'] || 200);

                let reply = {
                    headers: headerArr,
                    body: content,
                    statusCode: statusCode > 0 ? statusCode : 200,
                    isBase64Encoded: true
                };

                context.succeed(reply);
                //context.succeed({statusCode: 200, body: body});
                //context.succeed({statusCode: 200, body: JSON.stringify(event)});
                //context.succeed({statusCode: 200, body: JSON.stringify(reply)});
            });
        } else {
            let mimes = require('./mimes');
            let reply = {
                statusCode: 200,
                headers: {
                    'content-type': mimes[ext] || 'application/octet-stream',
                    'pragma': 'public',
                    'cache-control': 'max-age=2592000, public',
                    'expires': new Date(Date.now() + 865400 * 3000).toUTCString(),
                    'vary': 'Accept-Encoding',
                    'last-modified': new Date("01/01/1980").toUTCString()
                },
                body: fs.readFileSync(env.SCRIPT_PATH).toString('base64'),
                isBase64Encoded: true
            };

            context.succeed(reply);
            //context.succeed({statusCode: 200, body: JSON.stringify(reply)});
        }
    } catch (e) {
        context.succeed({statusCode: 200, body: 'error'});
    }
};