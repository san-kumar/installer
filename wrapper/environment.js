let path = require('path');
let process = require('process');
let fs = require('fs');

module.exports.parse = function (event) {
    let ev = Object.assign({
        path: '/',
        requestContext: {sourceIp: '127.0.0.1'},
        headers: {Host: 'localhost'}
    }, event);

    let root = '/var/task';
    let uri = ev.path || '';
    let file = root + '/' + uri.replace(/^\//, '');

    try {
        if (fs.lstatSync(file).isDirectory()) {
            file = fs.existsSync(file + '/index.html') ? file + '/index.html' : file + '/index.php';
        }
    } catch (e) {
    }

    if (!fs.existsSync(file)) {
        file = root + '/404.php';
    }

    let env = Object.assign({}, process.env, {
        REMOTE_HOST: ev.requestContext ? ev.requestContext.sourceIp : '127.0.0.1',
        DOCUMENT_ROOT: root,
        SERVER_NAME: ev.headers.Host,
        REQUEST_METHOD: ev.httpMethod,
        REQUEST_SCHEME: 'https',
        REQUEST_URI: uri,
        SERVER_PORT: 80,
        SCRIPT_NAME: path.basename(file),
        SCRIPT_EXT: (path.extname(file) || 'html').replace(/^\./, '').toLowerCase(),
        SCRIPT_PATH: file,
        SERVER_PROTOCOL: "HTTP/1.1",
        GATEWAY_INTERFACE: "CGI/1.1",

        REDIRECT_STATUS: 200,
        HTTP_ACCEPT: "text/html,application/xhtml+xml,application/xml;q: 0.9,*/*;q: 0.8",
        CONTENT_TYPE: ev.headers['content-type'] || "application/x-www-form-urlencoded",
        HTTP_CONNECTION: 'keep-alive',
        HTTP_UPGRADE_INSECURE_REQUESTS: '1',
        HTTP_ACCEPT_RANGES: 'none'
    });

    if (event.headers) {
        for (let key in event.headers) {
            if (event.headers.hasOwnProperty(key)) {
                let header = 'HTTP_' + key.toUpperCase().replace(/-/g, '_');
                env[header] = event.headers[key];
            }
        }
    }

    let final = Object.assign(env, {
        CONTENT_LENGTH: 0,
        SCRIPT_FILENAME: __dirname + "/lambdaphp.php",
        QUERY_STRING: '',
    });

    if (ev.body) {
        final.CONTENT_LENGTH = 100 * 1000 * 1000;
    }

    if (ev.queryStringParameters) {
        let keys = [];
        for (let key in ev.queryStringParameters) {
            if (ev.queryStringParameters.hasOwnProperty(key)) {
                keys.push(key + '=' + encodeURIComponent(ev.queryStringParameters[key]));
            }
        }

        final.QUERY_STRING = keys.join('&');
    }

    return final;
};